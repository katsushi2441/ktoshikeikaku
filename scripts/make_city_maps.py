#!/usr/bin/env python3
"""市区町村ごとの「用途地域マップ」の画像（1200×800・WebP）を作る。都市計画ナビの /city/<コード> に載せる。

  /usr/bin/python3 scripts/make_city_maps.py [--only 40203] [--pref 福岡県]
    → php/ktoshikeikaku_maps/<市区町村コード>.webp

- 図形は php/ktoshikeikaku_data/toshi_<都道府県>.sqlite の feat（build_db.py の pack() 形式・int32×1e7）。
- 背景に都市計画区域（tokei）を薄い灰色、その上に用途地域（youto）を一般的な色分けで塗る。地図の下地（道路・川）は無い。
- 色は「用途地域の色分け」の慣例に寄せた（住居系=緑〜黄、商業系=桃〜赤、工業系=紫〜青）。凡例に面積の割合を出す。
- 用途地域が1つも無い市区町村は作らない（ページ側は画像が無ければ出さない）。
- 2026-10-07: 「○○市 用途地域」で平均8〜10位。題名に「都市計画図」とあるのに図が無かったので作った。
"""
import argparse
import glob
import math
import os
import sqlite3
import struct

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "php", "ktoshikeikaku_data")
OUT = os.path.join(ROOT, "php", "ktoshikeikaku_maps")
SCALE = 10_000_000
W, H = 1200, 800
MAP_W = 800                      # 左の地図の幅（右は凡例）
SS = 2                           # 2倍で描いて縮める（線のギザギザを消す）
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
COLOR = {
    "第一種低層住居専用地域": "#00a37a", "第二種低層住居専用地域": "#6cc59a", "田園住居地域": "#b9d98a",
    "第一種中高層住居専用地域": "#9fd25f", "第二種中高層住居専用地域": "#cfe37a",
    "第一種住居地域": "#ffe766", "第二種住居地域": "#ffd27f", "準住居地域": "#ffb866",
    "近隣商業地域": "#ffa3c2", "商業地域": "#f2577f",
    "準工業地域": "#c7a3e6", "工業地域": "#8fcbee", "工業専用地域": "#4f93d6",
}
ORDER = list(COLOR)


def rings(b):
    """pack() の逆。[(外周, [穴…]), …] を返す（座標は度）。"""
    off = 0
    np_, = struct.unpack_from("<H", b, off); off += 2
    out = []
    for _ in range(np_):
        nr, = struct.unpack_from("<H", b, off); off += 2
        rs = []
        for _ in range(nr):
            nc, = struct.unpack_from("<I", b, off); off += 4
            pts = struct.unpack_from(f"<{nc * 2}l", b, off); off += nc * 8
            rs.append([(pts[i] / SCALE, pts[i + 1] / SCALE) for i in range(0, len(pts), 2)])
        out.append(rs)
    return out


def draw_city(code, city, pref, feats):
    youto = [(n, g) for layer, n, g in feats if layer == "youto" and n in COLOR]
    if not youto:
        return False
    tokei = [g for layer, n, g in feats if layer == "tokei"]
    ys = [(n, rings(g)) for n, g in youto]
    ts = [rings(g) for g in tokei]
    pts = [p for _, rr in ys for poly in rr for p in poly[0]]
    xs = [p[0] for p in pts]; yy = [p[1] for p in pts]
    minx, maxx, miny, maxy = min(xs), max(xs), min(yy), max(yy)
    k = math.cos(math.radians((miny + maxy) / 2))
    pad = 24
    sx = (MAP_W - 2 * pad) / max((maxx - minx) * k, 1e-9)
    sy = (H - 2 * pad - 30) / max(maxy - miny, 1e-9)
    s = min(sx, sy)
    ox = pad + ((MAP_W - 2 * pad) - (maxx - minx) * k * s) / 2
    oy = pad + ((H - 2 * pad - 30) - (maxy - miny) * s) / 2

    def P(lon, lat):
        return ((ox + (lon - minx) * k * s) * SS, (oy + (maxy - lat) * s) * SS)

    img = Image.new("RGB", (W * SS, H * SS), "#ffffff")
    d = ImageDraw.Draw(img)
    d.rectangle([0, 0, MAP_W * SS, H * SS], fill="#fbfcfd")
    for rr in ts:                                   # 都市計画区域（背景）
        for poly in rr:
            if len(poly[0]) > 2:
                d.polygon([P(*p) for p in poly[0]], fill="#eceff3")
    area = {}
    for n, rr in sorted(ys, key=lambda x: ORDER.index(x[0])):
        for poly in rr:
            if len(poly[0]) < 3:
                continue
            d.polygon([P(*p) for p in poly[0]], fill=COLOR[n], outline="#ffffff")
            for hole in poly[1:]:
                if len(hole) > 2:
                    d.polygon([P(*p) for p in hole], fill="#eceff3")
    img = img.resize((W, H), Image.LANCZOS)
    d = ImageDraw.Draw(img)
    # 縮尺（1km か 5km）
    km_px = s * (1 / 111.32)                         # 1km あたりのピクセル（緯度方向）
    unit = 1 if km_px * 1 >= 40 else 5 if km_px * 5 >= 40 else 10
    L = km_px * unit
    x0, y0 = 30, H - 28
    d.line([(x0, y0), (x0 + L, y0)], fill="#333", width=3)
    d.line([(x0, y0 - 6), (x0, y0 + 2)], fill="#333", width=2); d.line([(x0 + L, y0 - 6), (x0 + L, y0 + 2)], fill="#333", width=2)
    d.text((x0 + L + 8, y0 - 12), f"{unit}km", font=ImageFont.truetype(FR, 16), fill="#333")
    d.text((MAP_W - 40, 18), "N↑", font=ImageFont.truetype(FB, 18), fill="#333")
    # 凡例
    for _, g in youto:
        pass
    from collections import Counter
    # 面積の割合は main.sqlite の集計（ページの表と同じ数字）を使う
    st = sqlite3.connect(os.path.join(DATA, "main.sqlite")).execute("SELECT stats FROM city WHERE citycode=?", (code,)).fetchone()
    zone_ha = Counter()
    if st:
        import json
        for key, ha in (json.loads(st[0]).get("youto") or {}).items():
            zone_ha[key.split("|")[0]] += ha
    tot = sum(zone_ha.values()) or 1
    lx = MAP_W + 28
    d.text((lx, 26), f"{city}の用途地域", font=ImageFont.truetype(FB, 30 if len(city) <= 6 else 24), fill="#1b2a3a")
    d.text((lx, 70), f"{pref}・面積の割合", font=ImageFont.truetype(FR, 17), fill="#5b6676")
    f = ImageFont.truetype(FR, 18)
    y = 108
    for n in ORDER:
        if n not in zone_ha:
            continue
        d.rectangle([lx, y + 3, lx + 22, y + 21], fill=COLOR[n], outline="#cfd6de")
        pct = 100 * zone_ha[n] / tot
        d.text((lx + 32, y), n.replace("住居専用地域", "住居専用").replace("地域", ""), font=f, fill="#1f2933")
        d.text((W - 30, y), f"{pct:.1f}%", font=f, fill="#1f2933", anchor="ra")
        y += 34
    d.rectangle([lx, y + 3, lx + 22, y + 21], fill="#eceff3", outline="#cfd6de")
    d.text((lx + 32, y), "用途地域の指定なし（都市計画区域）", font=ImageFont.truetype(FR, 15), fill="#5b6676")
    src = ImageFont.truetype(FR, 13)
    d.text((lx, H - 74), "出典：国土交通省 都市局", font=src, fill="#7a8794")
    d.text((lx, H - 56), "「都市計画決定GISデータ」令和7年度 を加工", font=src, fill="#7a8794")
    d.text((lx, H - 38), "参考図です。正式には市区町村の都市計画図で確認", font=src, fill="#7a8794")
    d.text((lx, H - 20), "Kurage 都市計画ナビ", font=ImageFont.truetype(FB, 13), fill="#2f6f4f")
    img.save(os.path.join(OUT, f"{code}.webp"), "WEBP", quality=82, method=6)
    return True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only")
    ap.add_argument("--pref")
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    n = 0
    for f in sorted(glob.glob(os.path.join(DATA, "toshi_*.sqlite"))):
        pref = os.path.basename(f)[6:-7]
        if a.pref and pref != a.pref:
            continue
        c = sqlite3.connect(f)
        codes = [r[0] for r in c.execute("SELECT DISTINCT citycode FROM feat WHERE layer='youto'" + (" AND citycode=?" if a.only else ""), ((a.only,) if a.only else ()))]
        for code in codes:
            city = c.execute("SELECT city FROM feat WHERE citycode=? LIMIT 1", (code,)).fetchone()[0]
            feats = c.execute("SELECT layer, name, geom FROM feat WHERE citycode=? AND layer IN ('youto','tokei')", (code,)).fetchall()
            if draw_city(code, city, pref, feats):
                n += 1
        c.close()
    print("作成", n, "枚 →", OUT)


if __name__ == "__main__":
    main()
