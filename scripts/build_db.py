#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""都市計画決定GISデータ（全国）を、PHP 1ファイルから引ける SQLite にまとめる。

  /usr/bin/python3 scripts/build_db.py                  # 取得ずみの都道府県ぜんぶ
  /usr/bin/python3 scripts/build_db.py --pref 愛知県     # 都道府県を絞る

元データ: 国土交通省 都市局「都市計画決定GISデータ（全国）」令和7年度版
  /mnt/data/kminpaku/raw/youto/<都道府県>.zip（kminpaku/scripts/fetch_youto.py が落としたもの。取り直さない）

出力（php/ktoshikeikaku_data/）:
  toshi_<都道府県>.sqlite … その県の面と線（用途地域・区域区分・防火・高度地区・地区計画・都市計画道路…）
  main.sqlite            … 市区町村の一覧・収録レイヤー・面積の内訳（市区町村ページの材料）・時点

作りは kminpaku と同じ（2026-09-23 実測の知見）:
  - 座標は 1e-7 度の int32 に詰め、1m まで間引く（全国 GeoJSON 約1.9GB → 数十MB）
  - heteml の SQLite には rtree が無いので、外接矩形を普通の列で持って索引を張る
"""
from __future__ import annotations

import argparse
import json
import math
import os
import re
import sqlite3
import struct
import sys
import zipfile
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = "/mnt/data/kminpaku/raw/youto"
OUTDIR = os.path.join(ROOT, "php", "ktoshikeikaku_data")
SCALE = 10_000_000
TOL = 1e-5

# レイヤー → (日本語の分類, 名前にする属性の順)
LAYERS = {
    "youto": ("用途地域", ["YoutoName"]),
    "senbiki": ("区域区分", ["AreaType"]),
    "tokei": ("都市計画区域", ["TokeiType"]),
    "jyuntoshi": ("準都市計画区域", ["TokeiType", "AreaType"]),
    "bouka": ("防火地域・準防火地域", ["AreaType"]),
    "koudoti": ("高度地区", ["DistName", "DistType"]),
    "koudori": ("高度利用地区", ["DistName", "DistType"]),
    "tkbt": ("特別用途地区", ["YoutoName", "DistType"]),
    "tokuteiyouto": ("特定用途制限地域", ["DistName", "DistType", "AreaType"]),
    "chikukei": ("地区計画", ["DistName", "DistType"]),
    "fuuchichiku": ("風致地区", ["DistName", "DistType"]),
    "ritteki": ("立地適正化計画", ["AreaType"]),
    "tochiku": ("土地区画整理事業", ["DistName", "DistType", "AreaType"]),
    "kouen": ("都市計画公園・緑地", ["ParkName", "ParkType"]),
    "douro": ("都市計画道路", ["DouroName", "DouroType"]),
    "ryokukachiiki": ("緑化地域", ["DistName", "DistType", "AreaType"]),
    "toshisaisei": ("都市再生特別地区", ["DistName", "DistType"]),
    "tokuteiyuudou": ("特定用途誘導地区", ["DistName", "DistType"]),
    "tokuryoku": ("特例容積率適用地区", ["DistName", "DistType"]),
}

PREF_SCHEMA = """
CREATE TABLE feat (
  id INTEGER PRIMARY KEY, layer TEXT, citycode TEXT, city TEXT,
  name TEXT, kind TEXT, bcr TEXT, far TEXT, line INTEGER,
  minx REAL, maxx REAL, miny REAL, maxy REAL, geom BLOB);
CREATE INDEX feat_box ON feat(minx, maxx);
CREATE INDEX feat_city ON feat(citycode, layer);
CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
"""
MAIN_SCHEMA = """
CREATE TABLE city (
  citycode TEXT PRIMARY KEY, pref TEXT, city TEXT, layers TEXT, stats TEXT);
CREATE INDEX city_pref ON city(pref);
CREATE INDEX city_name ON city(city);
CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
"""


def simplify(pts: list, tol: float, closed: bool) -> list:
    if len(pts) < (5 if closed else 3):
        return pts

    def dp(pl):
        if len(pl) < 3:
            return pl
        x1, y1 = pl[0]; x2, y2 = pl[-1]
        dx, dy = x2 - x1, y2 - y1
        d2 = dx * dx + dy * dy
        best, bi = 0.0, 0
        for i in range(1, len(pl) - 1):
            x, y = pl[i]
            if d2 == 0:
                dd = (x - x1) ** 2 + (y - y1) ** 2
            else:
                t = max(0.0, min(1.0, ((x - x1) * dx + (y - y1) * dy) / d2))
                dd = (x - (x1 + t * dx)) ** 2 + (y - (y1 + t * dy)) ** 2
            if dd > best:
                best, bi = dd, i
        if math.sqrt(best) > tol:
            return dp(pl[:bi + 1])[:-1] + dp(pl[bi:])
        return [pl[0], pl[-1]]

    sys.setrecursionlimit(100000)
    r = dp(pts)
    return r if len(r) >= (4 if closed else 2) else pts


def parts_of(geom: dict):
    """(輪のリストのリスト, 線か) を返す。線は1本を1つの「面」の1輪として持つ。"""
    t = geom["type"]
    c = geom["coordinates"]
    if t == "Polygon":
        return [c], False
    if t == "MultiPolygon":
        return c, False
    if t == "LineString":
        return [[c]], True
    if t == "MultiLineString":
        return [[ln] for ln in c], True
    return [], False


def pack(geom: dict):
    polys, is_line = parts_of(geom)
    if not polys:
        return None
    out = [struct.pack("<H", len(polys))]
    xs, ys = [], []
    for poly in polys:
        out.append(struct.pack("<H", len(poly)))
        for ring in poly:
            r = simplify([(float(p[0]), float(p[1])) for p in ring], TOL, not is_line)
            out.append(struct.pack("<I", len(r)))
            for x, y in r:
                out.append(struct.pack("<ll", round(x * SCALE), round(y * SCALE)))
                xs.append(x); ys.append(y)
    return b"".join(out), min(xs), max(xs), min(ys), max(ys), is_line, polys


def ring_area_m2(ring) -> float:
    """経緯度の輪の面積(m²)。その輪の中心緯度で局所的に平面にして靴紐公式（市区町村の内訳用・誤差は数%以内）。"""
    if len(ring) < 4:
        return 0.0
    lat0 = sum(p[1] for p in ring) / len(ring)
    kx = 111320.0 * math.cos(math.radians(lat0))
    ky = 110540.0
    s = 0.0
    for (x1, y1), (x2, y2) in zip(ring, ring[1:]):
        s += (x1 * kx) * (y2 * ky) - (x2 * kx) * (y1 * ky)
    return abs(s) / 2.0


def poly_area_m2(polys) -> float:
    a = 0.0
    for poly in polys:
        if not poly:
            continue
        a += ring_area_m2(poly[0]) - sum(ring_area_m2(h) for h in poly[1:])
    return max(a, 0.0)


def num(v) -> str:
    """建ぺい率・容積率の値をそろえる（"60.0"→"60"、空・0以下→""）。元データは市区町村で書き方が違う。"""
    try:
        f = float(str(v).strip().replace("%", ""))
    except (TypeError, ValueError):
        return ""
    if f <= 0:
        return ""
    return str(int(f)) if f == int(f) else str(f)


def name_of(layer: str, props: dict) -> tuple[str, str]:
    keys = LAYERS[layer][1]
    vals = [str(props.get(k)).strip() for k in keys if props.get(k) not in (None, "", "null")]
    name = vals[0] if vals else LAYERS[layer][0]
    kind = vals[1] if len(vals) > 1 else ""
    # 全角数字の揺れ（第１種／第一種）をそろえる。表示も検索も漢数字に寄せる
    name = name.replace("第１種", "第一種").replace("第２種", "第二種").replace("第３種", "第三種")
    return name, kind


def build_pref(pref: str, zpath: str, main: sqlite3.Connection) -> int:
    out = os.path.join(OUTDIR, f"toshi_{pref}.sqlite")
    if os.path.exists(out):
        os.remove(out)
    db = sqlite3.connect(out)
    db.executescript(PREF_SCHEMA)
    n = 0
    stats: dict = defaultdict(lambda: defaultdict(lambda: defaultdict(float)))  # citycode→layer→name→m²
    counts: dict = defaultdict(lambda: defaultdict(int))
    city_names: dict = {}
    with zipfile.ZipFile(zpath) as z:
        for info in z.infolist():
            m = re.search(r"/(\d{5})_([^/]+)/\d{5}_([a-z]+)\.geojson$", info.filename)
            if not m:
                continue
            citycode, city, layer = m.group(1), m.group(2), m.group(3)
            if layer not in LAYERS:
                continue
            city_names[citycode] = city
            try:
                gj = json.loads(z.read(info).decode("utf-8"))
            except Exception as e:  # noqa: BLE001
                print(f"  読めない: {info.filename} {e}", file=sys.stderr)
                continue
            for ft in gj.get("features", []):
                g = ft.get("geometry")
                if not g:
                    continue
                pk = pack(g)
                if not pk:
                    continue
                blob, minx, maxx, miny, maxy, is_line, polys = pk
                p = ft.get("properties") or {}
                name, kind = name_of(layer, p)
                bcr = num(p.get("BCR")) if layer == "youto" else ""
                far = num(p.get("FAR")) if layer == "youto" else ""
                db.execute("INSERT INTO feat(layer,citycode,city,name,kind,bcr,far,line,minx,maxx,miny,maxy,geom) "
                           "VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
                           (layer, citycode, city, name, kind, bcr, far, int(is_line), minx, maxx, miny, maxy, blob))
                n += 1
                counts[citycode][layer] += 1
                if not is_line:
                    key = name if layer != "youto" else f"{name}|{bcr}|{far}"
                    stats[citycode][layer][key] += poly_area_m2(polys)
    db.execute("INSERT INTO meta VALUES('pref', ?)", (pref,))
    db.commit(); db.execute("VACUUM"); db.close()
    for cc, city in city_names.items():
        st = {layer: {k: round(v / 1e4, 2) for k, v in d.items()} for layer, d in stats[cc].items()}  # ha
        main.execute("INSERT OR REPLACE INTO city VALUES(?,?,?,?,?)",
                     (cc, pref, city, json.dumps(dict(counts[cc]), ensure_ascii=False),
                      json.dumps(st, ensure_ascii=False)))
    return n


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pref")
    a = ap.parse_args()
    os.makedirs(OUTDIR, exist_ok=True)
    mpath = os.path.join(OUTDIR, "main.sqlite")
    fresh = not os.path.exists(mpath)
    main_db = sqlite3.connect(mpath)
    if fresh:
        main_db.executescript(MAIN_SCHEMA)
    zips = sorted(f for f in os.listdir(RAW) if f.endswith(".zip"))
    total = 0
    for f in zips:
        pref = f[:-4]
        if a.pref and pref != a.pref:
            continue
        main_db.execute("DELETE FROM city WHERE pref=?", (pref,))
        n = build_pref(pref, os.path.join(RAW, f), main_db)
        main_db.commit()
        size = os.path.getsize(os.path.join(OUTDIR, f"toshi_{pref}.sqlite")) / 1e6
        print(f"  {pref}: {n:,} 件 {size:.1f}MB", flush=True)
        total += n
    main_db.execute("INSERT OR REPLACE INTO meta VALUES('source', ?)",
                    ("国土交通省 都市局「都市計画決定GISデータ（全国）」令和7年度版",))
    main_db.execute("INSERT OR REPLACE INTO meta VALUES('source_url', ?)",
                    ("https://www.mlit.go.jp/toshi/tosiko/toshi_tosiko_tk_000087.html",))
    main_db.execute("INSERT OR REPLACE INTO meta VALUES('scale', ?)", (str(SCALE),))
    main_db.commit(); main_db.close()
    print(f"合計 {total:,} 件")


if __name__ == "__main__":
    main()
