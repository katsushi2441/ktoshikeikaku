#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""配布ZIP（kappstore 同梱物）を作る。

  /usr/bin/python3 scripts/make_zip.py
  /usr/bin/python3 scripts/make_zip.py --pref 愛知県 --pref 岐阜県   # 都市計画を都道府県で絞る

同梱の LICENSE と README はこのプロジェクトのものを入れる（kminpaku で他製品の記述が残った前例）。
最後に中身を並べて、別製品の名前が混ざっていないか確かめられるように出す。
"""
from __future__ import annotations

import argparse
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOP = "kurage-toshikeikaku-navi"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pref", action="append", default=[])
    a = ap.parse_args()
    data = ROOT / "php" / "ktoshikeikaku_data"
    files = [
        (ROOT / "LICENSE", "LICENSE"),
        (ROOT / "README.md", "README.md"),
        (ROOT / "php" / "ktoshikeikaku.php", "ktoshikeikaku.php"),
        (ROOT / "php" / "ktoshikeikaku_config.example.php", "ktoshikeikaku_config.example.php"),
        (data / ".htaccess", "ktoshikeikaku_data/.htaccess"),
        (data / "main.sqlite", "ktoshikeikaku_data/main.sqlite"),
        (data / "law.json", "ktoshikeikaku_data/law.json"),
        (data / "terms.json", "ktoshikeikaku_data/terms.json"),
        (data / "national.json", "ktoshikeikaku_data/national.json"),
        (ROOT / "outputs" / "ktoshikeikaku_1200x630.png", "images/ogp/ktoshikeikaku.png"),
    ]
    files += [(p, "scripts/" + p.name) for p in sorted((ROOT / "scripts").glob("*.py"))
              if p.name not in ("deploy.py", "list_on_kappstore.py", "make_zip.py")]
    files += [(p, "data/law/" + p.name) for p in sorted((ROOT / "data" / "law").glob("*.xml"))]
    toshi = sorted(data.glob("toshi_*.sqlite"))
    if a.pref:
        toshi = [p for p in toshi if p.stem[len("toshi_"):] in a.pref]
    files += [(p, f"ktoshikeikaku_data/{p.name}") for p in toshi]

    missing = [str(s) for s, _ in files if not s.exists()]
    if missing:
        print("!! 無いファイル:", *missing, sep="\n  ", file=sys.stderr)
        return 1
    out = ROOT / "outputs" / f"{TOP}.zip"
    out.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for src, name in files:
            z.write(src, f"{TOP}/{name}")
    print(f"→ {out}  {out.stat().st_size / 1e6:.1f}MB  都市計画 {len(toshi)}都道府県")
    with zipfile.ZipFile(out) as z:
        for n in z.namelist():
            if "/toshi_" not in n:
                print("   ", n)
        print(f"    …ほか 都市計画 {len(toshi)}ファイル")
    return 0


if __name__ == "__main__":
    sys.exit(main())
