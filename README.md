# Kurage 都市計画ナビ（ktoshikeikaku）

住所を入れると、その地点の都市計画を一度に返すシステムです。

- 用途地域と、その場所の建ぺい率・容積率
- 市街化区域／市街化調整区域（区域区分）・都市計画区域・準都市計画区域
- 防火地域・準防火地域、高度地区・高度利用地区、特別用途地区、地区計画、風致地区
- 立地適正化計画（居住誘導区域・都市機能誘導区域）
- 約20m以内にある都市計画道路

データは国土交通省 都市局「都市計画決定GISデータ（全国）」令和7年度版です。47都道府県・1,377市区町村・約41.8万区域を収録しています。

公開版: https://kurage.exbridge.jp/ktoshikeikaku.php/

## しないこと

- **建てられる・建てられないを断定しません。** 出典の国土交通省も「建築確認申請や不動産重要事項説明等の手続に用いることを保証するものではなく、参考情報として利用を想定」としています。最後は市区町村の窓口で確かめてください。
- **収録していない市区町村を「区域外」と書きません。**「未収録」と書いて区別します。
- **条文を手で書き写しません。** 都市計画法・建築基準法・都市再生特別措置法の本文は、e-Gov 法令API の XML から `scripts/extract_law.py` で機械的に取り出して引用します。
- **AIに結論を作らせません。** 結論は判定結果から規則で作ります（`verdict()`）。AIチャットは、判定結果と条文だけを材料に言い換えるだけです。
- 角地の建ぺい率緩和、前面道路の幅による容積率の制限、条例の上乗せは判定に含みません。

## 画面とAPI

| URL | 内容 |
|---|---|
| `/` | 住所で調べる＋AIチャット |
| `/check?q=住所` | 判定 |
| `/yogo/{slug}` | 用語・用途地域13種・調べ方（34ページ） |
| `/yoto` | 用途地域13種の一覧 |
| `/pref/{01〜47}` `/city/{市区町村コード}` | 都道府県・市区町村の内訳 |
| `/api?q=住所` | JSON |
| `/mcp` | MCP（Streamable HTTP） |
| `/chat` | AIチャット（POST） |
| `/data` `/about` `/llms.txt` `/sitemap.xml` | 出典・説明・検索エンジン向け |

## AIエージェントから使う（MCP）

```sh
claude mcp add --transport http ktoshikeikaku https://kurage.exbridge.jp/ktoshikeikaku.php/mcp
```

認証は要りません。ツールは3つです。

- `toshikeikaku_lookup` … 住所（または緯度経度）→ 都市計画
- `toshikeikaku_term` … 用語 → 説明と条文の原文（用途地域なら建築基準法 別表第二の該当項も）
- `toshikeikaku_city` … 市区町村 → 用途地域ごとの面積、建ぺい率・容積率の組み合わせ、市街化区域／市街化調整区域の面積

## 置き方（配布ZIP・データ込み）

1. `ktoshikeikaku.php` と `ktoshikeikaku_data/` を、PHP 8 が動く場所に置く（heteml なら、その階層の `.htaccess` に `AddHandler php-script .php`）
2. `ktoshikeikaku_config.example.php` を `ktoshikeikaku_config.php` にコピーして、必要なら中身を直す
   - `promo` … false で当社の商品案内を消す
   - `relay_base` / `model` … AIチャットに使う OpenAI 互換の窓口（Ollama なら `http://<host>:11434/v1`）。空ならAIを使わず、判定結果だけを返す
3. ブラウザで `https://<あなたのサイト>/ktoshikeikaku.php/` を開く

PHP 8 と PDO SQLite だけで動きます。データベースサーバーも常駐プロセスも要りません（拡張の rtree も使いません）。
`ktoshikeikaku_data/` は `.htaccess` の `Deny from all` で直読み禁止にしてあります。要る都道府県の `toshi_<都道府県>.sqlite` だけ置いても動き、置いていない県は「未収録」と返します。

## データを作り直す

国土交通省のページから都道府県別の zip を落として1か所に置き、次を実行します。

```sh
export KTOSHI_RAW=/path/to/zips                 # <都道府県>.zip を置いた場所
/usr/bin/python3 scripts/build_db.py            # → toshi_<都道府県>.sqlite, main.sqlite
/usr/bin/python3 scripts/national_stats.py      # → national.json（全国の集計）
/usr/bin/python3 scripts/extract_law.py         # → law.json（条文。data/law/*.xml から）
/usr/bin/python3 scripts/terms.py               # → terms.json（用語ページ）
```

図形は緯度経度を 1e-7 度の int32 に詰め、1m で間引いて SQLite に入れています。範囲の絞り込みは bbox 列で行います。
ローカルで見るときは `cd php && php -S 127.0.0.1:8000 -t .` → `http://127.0.0.1:8000/ktoshikeikaku.php/`。

## 出典

- 都市計画: 国土交通省 都市局「都市計画決定GISデータ（全国）」令和7年度版 https://www.mlit.go.jp/toshi/tosiko/toshi_tosiko_tk_000087.html
- 条文: e-Gov 法令検索（法令API）
- 住所の位置: 国土地理院 地名検索

## ライセンス

MIT（コード）。データはそれぞれの出典の利用条件に従ってください。

株式会社エクスブリッジ（名古屋）
