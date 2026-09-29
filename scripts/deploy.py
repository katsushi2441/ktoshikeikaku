#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ktoshikeikaku を heteml（kurage.exbridge.jp）へ上げる。

  /usr/bin/python3 scripts/deploy.py           # 全部（都市計画47都道府県ぶん 約230MB を含む）
  /usr/bin/python3 scripts/deploy.py --php     # PHP と OGP だけ（SQLite を送らない）

**FTP は1接続にまとめる。** 短時間に接続を重ねると、うちのIPが全ポートで
15〜20分遮断される（[[reference_heteml_ftp_block]]）。確認は HTTPS で行う。

置き場所:
  /web/kurage_exbridge_jp/ktoshikeikaku.php
  /web/kurage_exbridge_jp/ktoshikeikaku_data/…   … .htaccess で直読み禁止
  /web/kurage_exbridge_jp/images/ogp/ktoshikeikaku.png
"""
import ftplib
import os
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = "https://kurage.exbridge.jp"
REMOTE = "/web/kurage_exbridge_jp"
PHP_ONLY = "--php" in sys.argv
DATA = os.path.join(ROOT, "php", "ktoshikeikaku_data")

FILES = [(f"{ROOT}/php/ktoshikeikaku.php", f"{REMOTE}/ktoshikeikaku.php"),
         (f"{DATA}/.htaccess", f"{REMOTE}/ktoshikeikaku_data/.htaccess"),
         (f"{ROOT}/php/ktoshikeikaku_config.php", f"{REMOTE}/ktoshikeikaku_config.php"),
         (f"{ROOT}/outputs/ktoshikeikaku_1200x630.png", f"{REMOTE}/images/ogp/ktoshikeikaku.png")]
for f in ("law.json", "terms.json", "national.json", "main.sqlite"):   # 画面の文面と集計は --php でも送る
    FILES.append((os.path.join(DATA, f), f"{REMOTE}/ktoshikeikaku_data/{f}"))
if not PHP_ONLY:
    for f in sorted(os.listdir(DATA)):
        if f.startswith("toshi_") and f.endswith(".sqlite"):
            FILES.append((os.path.join(DATA, f), f"{REMOTE}/ktoshikeikaku_data/{f}"))


def env():
    for line in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in line and not line.startswith("#"):
            k, v = line.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main() -> int:
    env()
    total = sum(os.path.getsize(l) for l, _ in FILES)
    print(f"{len(FILES)}ファイル / {total/1e6:.1f}MB を1接続で送ります")
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=1800)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    cur = None
    for local, remote in FILES:
        d = os.path.dirname(remote)
        if d != cur:
            try:
                f.cwd(d)
            except ftplib.error_perm:
                f.mkd(d); f.cwd(d)
            cur = d
        with open(local, "rb") as fh:
            f.storbinary("STOR " + os.path.basename(remote), fh, blocksize=1 << 18)
        print(f"  {remote}  {os.path.getsize(local)/1024:.0f}KB", flush=True)
    f.quit()

    for path in ("/ktoshikeikaku.php/", "/ktoshikeikaku.php/yoto", "/ktoshikeikaku.php/city/23100", "/ktoshikeikaku.php/data",
                 "/ktoshikeikaku.php/about", "/images/ogp/ktoshikeikaku.png"):
        try:
            with urllib.request.urlopen(urllib.request.Request(BASE + path, headers={"User-Agent": "ktoshikeikaku-deploy/1.0"}), timeout=90) as r:
                print(f"  {r.status} {len(r.read(400))}B+ {BASE}{path}")
        except Exception as e:
            print(f"  ! {BASE}{path}: {e}")
    try:
        with urllib.request.urlopen(BASE + "/ktoshikeikaku_data/main.sqlite", timeout=60) as r:
            print(f"  ! SQLite が直接読めてしまう: {r.status}")
    except Exception as e:
        print(f"  {getattr(e, 'code', '?')} SQLite の直読みは拒否されている（想定どおり）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
