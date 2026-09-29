#!/usr/bin/env python3
"""全国の集計（用途地域ごとの建ぺい率・容積率の組み合わせと面積、区域区分・防火などの合計）。
市区町村ページの stats（build_db.py が作る）を足し合わせる。→ php/ktoshikeikaku_data/national.json"""
import json, os, sqlite3
from collections import defaultdict
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
D = os.path.join(ROOT, 'php', 'ktoshikeikaku_data')
c = sqlite3.connect(os.path.join(D, 'main.sqlite'))
youto = defaultdict(lambda: defaultdict(float)); youto_cities = defaultdict(set)
layer_tot = defaultdict(lambda: defaultdict(float)); layer_cities = defaultdict(lambda: defaultdict(set))
for cc, city, st in c.execute('select citycode, city, stats from city'):
    s = json.loads(st)
    for k, ha in s.get('youto', {}).items():
        name, bcr, far = k.split('|')
        youto[name][f'{bcr}|{far}'] += ha; youto_cities[name].add(cc)
    for layer, d in s.items():
        if layer == 'youto': continue
        for name, ha in d.items():
            layer_tot[layer][name] += ha; layer_cities[layer][name].add(cc)
out = {'youto': {}, 'layers': {}, 'cities': c.execute('select count(*) from city').fetchone()[0]}
for name, d in youto.items():
    tot = sum(d.values())
    combos = sorted(d.items(), key=lambda x: -x[1])
    out['youto'][name] = {'ha': round(tot, 1), 'cities': len(youto_cities[name]),
                          'combos': [{'bcr': k.split('|')[0], 'far': k.split('|')[1], 'ha': round(v, 1),
                                      'share': round(100 * v / tot, 1)} for k, v in combos[:6]]}
for layer, d in layer_tot.items():
    out['layers'][layer] = {n: {'ha': round(v, 1), 'cities': len(layer_cities[layer][n])} for n, v in
                            sorted(d.items(), key=lambda x: -x[1])[:12]}
json.dump(out, open(os.path.join(D, 'national.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
for name in ['第一種低層住居専用地域', '第一種住居地域', '商業地域', '工業専用地域']:
    y = out['youto'].get(name); print(name, y and (y['ha'], y['cities'], y['combos'][:3]))
print('senbiki', out['layers'].get('senbiki'))
