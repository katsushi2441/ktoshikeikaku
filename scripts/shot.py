#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ローカルの ktoshikeikaku.php を端末幅で開き、横はみ出し(scrollWidth)を実測して撮る。

  /usr/bin/python3 scripts/shot.py http://127.0.0.1:<port>/ktoshikeikaku.php outputs/shots
（kghome/scripts/shot.py と同じ考え方。headless の --window-size は切り取り幅にしかならない）
"""
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

WIDTHS = (320, 360, 390)
PAGES = {"top": "/", "check": "/check?q=愛知県名古屋市中区三の丸3-1-1", "zone": "/yogo/dai1shu-teiso",
         "kenpei": "/yogo/kenpeiritsu", "yoto": "/yoto", "pref": "/pref/23", "city": "/city/23100", "about": "/about"}


def main():
    base, out = sys.argv[1].rstrip("/"), Path(sys.argv[2])
    out.mkdir(parents=True, exist_ok=True)
    bad = 0
    with sync_playwright() as p:
        b = p.chromium.launch()
        for w in WIDTHS:
            pg = b.new_page(viewport={"width": w, "height": 800}, device_scale_factor=1)
            for name, path in PAGES.items():
                pg.goto(base + path, wait_until="load", timeout=60000)
                sw = pg.evaluate("document.documentElement.scrollWidth")
                wide = pg.evaluate("""w=>[...document.querySelectorAll('body *')].filter(e=>{const r=e.getBoundingClientRect();
                    return r.right>w+1 && !e.closest('.scroll-x')}).slice(0,3).map(e=>e.tagName+'.'+e.className)""", w)
                ok = sw <= w
                bad += not ok
                print(f"{w} {name:7s} scrollWidth={sw} {'OK' if ok else 'はみ出し ' + str(wide)}")
                if w == 360:
                    pg.screenshot(path=str(out / f"{name}_{w}.png"), full_page=False)
            pg.close()
        b.close()
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
