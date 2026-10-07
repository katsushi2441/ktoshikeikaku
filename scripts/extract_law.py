#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""用語ページで引用する条文を、e-Gov 法令API（v1）の本文から機械的に取り出す。

  /usr/bin/python3 scripts/extract_law.py   # data/law/*.xml → php/ktoshikeikaku_data/law.json

**条文は手で書き写さない。** 項番号や文言を記憶で書くと、改正でずれた番号を載せる事故になる
（都市計画法第9条は田園住居地域の追加で項がずれている）。本文の XML から取り出して、そのまま引用する。
取り直すとき: curl -s -o data/law/<法令ID>.xml https://laws.e-gov.go.jp/api/1/lawdata/<法令ID>
"""
import json
import os
import re
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LAWDIR = os.path.join(ROOT, "data", "law")
OUT = os.path.join(ROOT, "php", "ktoshikeikaku_data", "law.json")
LAWS = {
    "都市計画法": ("343AC0000000100", "https://laws.e-gov.go.jp/law/343AC0000000100"),
    "建築基準法": ("325AC0000000201", "https://laws.e-gov.go.jp/law/325AC0000000201"),
    "都市再生特別措置法": ("414AC0000000022", "https://laws.e-gov.go.jp/law/414AC0000000022"),
}
# (法令, 条, 項, 号 or None) → 引用のキー
WANT = [
    ("都市計画法", "5", "1", None), ("都市計画法", "5_2", "1", None),
    ("都市計画法", "7", "1", None), ("都市計画法", "7", "2", None), ("都市計画法", "7", "3", None),
] + [("都市計画法", "9", str(i), None) for i in range(1, 23)] + [
    ("都市計画法", "12_5", "1", None), ("都市計画法", "29", "1", None), ("都市計画法", "34", "1", None),
    ("都市計画法", "53", "1", None), ("建築基準法", "52", "2", None), ("建築基準法", "53", "3", None),
    ("建築基準法", "42", "1", None), ("建築基準法", "43", "1", None),
    ("建築基準法", "52", "1", None), ("建築基準法", "53", "1", None),
    ("建築基準法", "58", "1", None), ("建築基準法", "61", "1", None),
    ("都市再生特別措置法", "81", "1", None), ("都市再生特別措置法", "81", "2", "2"),
    ("都市再生特別措置法", "81", "2", "3"),
    # 2026-10-07 建ぺい率・容積率の計算ページ（/keisan）用：前面道路の数値・角地と防火の緩和・制限がかからない場合
    ("建築基準法", "52", "2", "1"), ("建築基準法", "52", "2", "2"), ("建築基準法", "52", "2", "3"),
    ("建築基準法", "53", "3", "1"), ("建築基準法", "53", "3", "2"), ("建築基準法", "53", "6", None), ("建築基準法", "53", "6", "1"),
]
KANJI = {"1": "一", "2": "二", "3": "三", "4": "四", "5": "五"}


def clean(s: str) -> str:
    return re.sub(r"\s+", "", s)


def cite(law: str, art: str, para: str, item) -> str:
    a = art.replace("_", "条の")
    s = f"{law}第{a}条" if "条の" not in a else f"{law}第{a}"
    if para != "1" or item:
        s += f"第{para}項"
    if item:
        s += f"第{KANJI.get(item, item)}号"
    return s


def main():
    roots = {law: ET.fromstring(open(os.path.join(LAWDIR, fid + ".xml"), encoding="utf-8").read())
             for law, (fid, _) in LAWS.items()}
    out = {}
    for law, art, para, item in WANT:
        root = roots[law]
        a = next((x for x in root.iter("Article") if x.get("Num") == art), None)
        if a is None:
            raise SystemExit(f"条が見つからない: {law} {art}")
        p = next((x for x in a.findall("Paragraph") if x.get("Num") == para), None)
        if p is None:
            raise SystemExit(f"項が見つからない: {law} {art} {para}")
        if item:
            it = next((x for x in p.findall("Item") if x.get("Num") == item), None)
            text = clean("".join(it.find("ItemSentence").itertext()))
        else:
            text = clean("".join(p.find("ParagraphSentence").itertext()))
        key = f"{law}:{art}:{para}" + (f":{item}" if item else "")
        out[key] = {"cite": cite(law, art, para, item), "text": text, "url": LAWS[law][1]}
    # 建築基準法 別表第二（用途地域ごとに建てられる／建ててはならない建築物）。項（い）〜（わ）を号ごとに取り出す
    kroot = roots["建築基準法"]
    tab = next(t for t in kroot.iter("AppdxTable") if "別表第二" in "".join(t.find("AppdxTableTitle").itertext()))
    appdx2 = {}
    for row in tab.findall(".//TableRow"):
        cols = row.findall("TableColumn")
        if len(cols) < 3:
            continue
        letter = clean("".join(cols[0].itertext())).strip("（）()")
        title = clean("".join(cols[1].itertext()))
        # 号は <Sentence Num=…>一　住宅</Sentence> の並び（号番号と本文は全角空白で区切られている）
        items = [re.sub(r"\s+", "", "".join(x.itertext())) and "".join(x.itertext()).strip()
                 for x in cols[2].findall("Sentence")]
        items = [re.sub(r"[ \t\n]+", "", x) for x in items if x]
        if not items:
            items = [clean("".join(cols[2].itertext()))]
        appdx2[letter] = {"title": title, "items": items}
    out_appdx = appdx2
    asof = {law: (re.search(r'<LawNum>([^<]+)', open(os.path.join(LAWDIR, fid + ".xml"), encoding="utf-8").read()) or [None, ""])[1]
            for law, (fid, _) in LAWS.items()}
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump({"quotes": out, "appdx2": out_appdx, "lawnum": asof, "source": "e-Gov法令検索（法令API）"}, open(OUT, "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)
    print(f"{len(out)} 件 → {OUT}")
    for k in ["都市計画法:7:3", "都市計画法:9:21", "建築基準法:53:1", "都市再生特別措置法:81:2:2"]:
        print(" ", out[k]["cite"], out[k]["text"][:60])


if __name__ == "__main__":
    main()
