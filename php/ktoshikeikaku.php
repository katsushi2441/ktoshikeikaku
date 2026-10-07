<?php
/**
 * Kurage 都市計画ナビ（ktoshikeikaku）
 *
 * 住所を入れると、その地点の都市計画（用途地域・建ぺい率・容積率・市街化区域／市街化調整区域・
 * 防火地域・高度地区・地区計画・風致地区・居住誘導区域・近くの都市計画道路）を一度に返す。
 * 用語・用途地域13種・調べ方・都道府県・市区町村（約1,400）のページに、同じ住所検索とAIチャットを置く。
 *
 *   /                         住所で調べる（トップ）
 *   /check?q=住所 /check?lat=&lon=   判定
 *   /yogo/{slug}              用語・用途地域・調べ方のページ
 *   /yoto                     用途地域13種の一覧
 *   /pref/{コード}             都道府県
 *   /city/{市区町村コード}      市区町村
 *   /chat (POST)              AIチャット（答えの中身は判定結果と条文だけ。AIは言い換えるだけ）
 *   /api?q= /mcp（AIエージェント用 MCP・Streamable HTTP） /data /about /llms.txt /robots.txt /sitemap.xml
 *
 * 守ること:
 *   - 結論はデータから決める。AIは判定結果と条文の引用を言い換えるだけで、中身を足さない
 *   - 収録していない市区町村を「区域外」と書かない（「未収録」）。時点と出典を必ず添える
 *   - 出典（国土交通省）の注意書き「建築確認申請や不動産重要事項説明等の手続に用いることを
 *     保証するものではなく、参考情報」を、判定の画面に必ずそのまま出す
 * heteml: その階層の .htaccess に `AddHandler php-script .php`。PHP 8 + PDO SQLite だけで動く。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
@include_once __DIR__ . '/simpletrack.php';

$SITE = 'Kurage 都市計画ナビ';
$DIR  = __DIR__ . '/ktoshikeikaku_data';
$LOGO = 'https://exbridge.jp/images/logo-mark-128.png';
$GSI  = 'https://msearch.gsi.go.jp/address-search/AddressSearch';
$CFG  = [];
if (is_file(__DIR__ . '/ktoshikeikaku_config.php')) { $CFG = (array)(include __DIR__ . '/ktoshikeikaku_config.php'); }
// 置いた場所に合わせる（自社サーバーに置いたときも canonical・サイトマップが自分のURLになる）
$SELF = (string)($CFG['self'] ?? ($_SERVER['SCRIPT_NAME'] ?? '/ktoshikeikaku.php'));
$ORIGIN = (string)($CFG['origin'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'kurage.exbridge.jp')));
$OGP  = (string)($CFG['ogp'] ?? ($ORIGIN . '/images/ogp/ktoshikeikaku.png'));
$STORE = (string)($CFG['store_url'] ?? 'https://kappstore.exbridge.jp/app.php?id=01d71e3bd7f717a8');   // 重説 災害項目チェック
$PROMO = (bool)($CFG['promo'] ?? true);   // 当社の商品案内を出すか（自社に置くときは false）
$PREF_NAMES = ['01' => '北海道', '02' => '青森県', '03' => '岩手県', '04' => '宮城県', '05' => '秋田県', '06' => '山形県', '07' => '福島県', '08' => '茨城県', '09' => '栃木県', '10' => '群馬県', '11' => '埼玉県', '12' => '千葉県', '13' => '東京都', '14' => '神奈川県', '15' => '新潟県', '16' => '富山県', '17' => '石川県', '18' => '福井県', '19' => '山梨県', '20' => '長野県', '21' => '岐阜県', '22' => '静岡県', '23' => '愛知県', '24' => '三重県', '25' => '滋賀県', '26' => '京都府', '27' => '大阪府', '28' => '兵庫県', '29' => '奈良県', '30' => '和歌山県', '31' => '鳥取県', '32' => '島根県', '33' => '岡山県', '34' => '広島県', '35' => '山口県', '36' => '徳島県', '37' => '香川県', '38' => '愛媛県', '39' => '高知県', '40' => '福岡県', '41' => '佐賀県', '42' => '長崎県', '43' => '熊本県', '44' => '大分県', '45' => '宮崎県', '46' => '鹿児島県', '47' => '沖縄県'];
$LAYER_LABEL = ['youto' => '用途地域', 'senbiki' => '区域区分', 'tokei' => '都市計画区域', 'jyuntoshi' => '準都市計画区域',
    'bouka' => '防火地域・準防火地域', 'koudoti' => '高度地区', 'koudori' => '高度利用地区', 'tkbt' => '特別用途地区',
    'tokuteiyouto' => '特定用途制限地域', 'chikukei' => '地区計画', 'fuuchichiku' => '風致地区', 'ritteki' => '立地適正化計画',
    'tochiku' => '土地区画整理事業', 'kouen' => '都市計画公園・緑地', 'douro' => '都市計画道路', 'ryokukachiiki' => '緑化地域',
    'toshisaisei' => '都市再生特別地区', 'tokuteiyuudou' => '特定用途誘導地区', 'tokuryoku' => '特例容積率適用地区'];
$LAYER_TERM = ['youto' => 'yoto-chiiki', 'senbiki' => 'shigaika-chosei-kuiki', 'tokei' => 'toshikeikaku-kuiki', 'jyuntoshi' => 'toshikeikaku-kuiki',
    'bouka' => 'boka-chiiki', 'koudoti' => 'kodo-chiku', 'tkbt' => 'tokubetsu-yoto-chiku', 'chikukei' => 'chiku-keikaku',
    'fuuchichiku' => 'fuchi-chiku', 'ritteki' => 'ricchi-tekiseika', 'douro' => 'toshikeikaku-doro'];
const NOTE_MLIT = '出典の国土交通省は「建築確認申請や不動産重要事項説明等の手続に用いることを保証するものではなく、参考情報として利用を想定」としています。正式な内容は市区町村の都市計画課の窓口や都市計画図書で確かめてください。';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((float)$v); }
function jd($j) { $a = json_decode((string)$j, true); return is_array($a) ? $a : []; }
function jfile($f) { global $DIR; $p = $DIR . '/' . $f; return is_file($p) ? (json_decode((string)file_get_contents($p), true) ?: []) : []; }
function u($p) { global $SELF; return $SELF . $p; }   // このシステムの中のリンク（heteml で直に配信するので絶対パスでよい）

try {
    $db = new PDO('sqlite:' . $DIR . '/main.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(503); header('Content-Type: text/plain; charset=UTF-8');
    echo 'データベースがありません。scripts/build_db.py で作って ktoshikeikaku_data/ に置いてください。'; exit;
}
$META = []; foreach ($db->query('SELECT k, v FROM meta') as $r) { $META[$r['k']] = $r['v']; }
$SCALE = (float)($META['scale'] ?? 10000000);
$LAW = jfile('law.json'); $QUOTES = $LAW['quotes'] ?? []; $APPDX = $LAW['appdx2'] ?? [];
$TERMS = []; foreach ((jfile('terms.json')['terms'] ?? []) as $t) { $TERMS[$t['slug']] = $t; }
$NAT = jfile('national.json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (strpos($path, $SELF) === 0) { $path = substr($path, strlen($SELF)); }
$path = '/' . trim(rawurldecode((string)$path), '/');
$path = $path === '/' ? '' : $path;

// ── 判定 ───────────────────────────────────────────────
function geocode($q) {
    global $GSI;
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: ktoshikeikaku/1.0\r\n"]]);
    $j = @file_get_contents($GSI . '?q=' . rawurlencode($q), false, $ctx);
    if ($j === false) return null;
    $items = json_decode($j, true);
    if (!is_array($items) || !$items) return null;
    $best = null; $bs = -1;
    foreach ($items as $it) {
        $t = $it['properties']['title'] ?? '';
        $s = (mb_strpos($t, $q) !== false ? 4 : 0) + (mb_strpos($t, $q) === 0 ? 2 : 0) - mb_strlen($t) / 100;
        if ($s > $bs) { $bs = $s; $best = $it; }
    }
    if (!$best) return null;
    return ['lon' => (float)$best['geometry']['coordinates'][0], 'lat' => (float)$best['geometry']['coordinates'][1],
            'title' => (string)($best['properties']['title'] ?? '')];
}

/** 詰めた面（int32）の中に点があるか。形式は scripts/build_db.py の pack() と対。 */
function in_packed(string $b, float $lon, float $lat, float $scale): bool {
    $x = $lon * $scale; $y = $lat * $scale; $off = 0; $len = strlen($b);
    if ($len < 2) return false;
    $np = unpack('v', substr($b, $off, 2))[1]; $off += 2;
    for ($p = 0; $p < $np; $p++) {
        if ($off + 2 > $len) return false;
        $nr = unpack('v', substr($b, $off, 2))[1]; $off += 2;
        $inside = false; $hole = false;
        for ($r = 0; $r < $nr; $r++) {
            if ($off + 4 > $len) return false;
            $nc = unpack('V', substr($b, $off, 4))[1]; $off += 4;
            $need = $nc * 8; if ($off + $need > $len) return false;
            $pts = unpack('l*', substr($b, $off, $need)); $off += $need;
            if ($hole || ($r > 0 && !$inside)) continue;
            $in = false;
            for ($i = 0; $i < $nc; $i++) {
                $x1 = $pts[$i * 2 + 1]; $y1 = $pts[$i * 2 + 2]; $j = ($i + 1) % $nc;
                $x2 = $pts[$j * 2 + 1]; $y2 = $pts[$j * 2 + 2];
                if (($y1 > $y) != ($y2 > $y)) { $xx = ($x2 - $x1) * ($y - $y1) / ($y2 - $y1) + $x1; if ($x < $xx) $in = !$in; }
            }
            if ($r === 0) { $inside = $in; } elseif ($in) { $hole = true; }
        }
        if ($inside && !$hole) return true;
    }
    return false;
}

/** 詰めた線と点の最短距離（メートル）。 */
function line_dist_m(string $b, float $lon, float $lat, float $scale): float {
    $kx = 111320.0 * cos(deg2rad($lat)); $ky = 110540.0; $best = INF; $off = 0; $len = strlen($b);
    $np = unpack('v', substr($b, $off, 2))[1]; $off += 2;
    for ($p = 0; $p < $np; $p++) {
        $nr = unpack('v', substr($b, $off, 2))[1]; $off += 2;
        for ($r = 0; $r < $nr; $r++) {
            $nc = unpack('V', substr($b, $off, 4))[1]; $off += 4;
            $pts = unpack('l*', substr($b, $off, $nc * 8)); $off += $nc * 8;
            for ($i = 0; $i + 1 < $nc; $i++) {
                $ax = ($pts[$i * 2 + 1] / $scale - $lon) * $kx; $ay = ($pts[$i * 2 + 2] / $scale - $lat) * $ky;
                $bx = ($pts[$i * 2 + 3] / $scale - $lon) * $kx; $by = ($pts[$i * 2 + 4] / $scale - $lat) * $ky;
                $dx = $bx - $ax; $dy = $by - $ay; $d2 = $dx * $dx + $dy * $dy;
                $t = $d2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $d2)) : 0.0;
                $cx = $ax + $t * $dx; $cy = $ay + $t * $dy; $best = min($best, sqrt($cx * $cx + $cy * $cy));
            }
        }
    }
    return $best;
}

function pref_of(string $title): string {
    // 「京都府京都市」を最短一致で切ると「京都」になる（kminpaku 2026-09-23 実測）。47の名前で照合する
    foreach ($GLOBALS['PREF_NAMES'] as $p) { if (mb_strpos($title, $p) === 0) return $p; }
    return '';
}

function lookup(float $lon, float $lat, string $pref): array {
    global $DIR, $SCALE;
    $f = $DIR . '/toshi_' . $pref . '.sqlite';
    if ($pref === '' || !is_file($f)) return ['covered' => false, 'layers' => []];
    $y = new PDO('sqlite:' . $f); $y->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pad = 0.0003;   // 都市計画道路の線を約20〜30mの範囲で拾う
    $st = $y->prepare('SELECT layer, citycode, city, name, kind, bcr, far, line, geom FROM feat
                       WHERE minx <= ? AND maxx >= ? AND miny <= ? AND maxy >= ?');
    $st->execute([$lon + $pad, $lon - $pad, $lat + $pad, $lat - $pad]);
    $hit = []; $city = ''; $citycode = '';
    foreach ($st as $row) {
        if ((int)$row['line'] === 1) {
            $d = line_dist_m($row['geom'], $lon, $lat, $SCALE);
            if ($d <= 20.0) { $hit['douro'][] = ['name' => $row['name'], 'kind' => $row['kind'], 'dist_m' => (int)round($d)]; }
            continue;
        }
        if (!in_packed($row['geom'], $lon, $lat, $SCALE)) continue;
        $item = ['name' => $row['name']];
        if ($row['kind'] !== '') $item['kind'] = $row['kind'];
        if ($row['layer'] === 'youto') { $item['bcr'] = $row['bcr']; $item['far'] = $row['far']; }
        $hit[$row['layer']][] = $item;
        if ($city === '') { $city = $row['city']; $citycode = $row['citycode']; }
    }
    foreach ($hit as $k => $v) {   // 同じ内容の重複を落とす
        $seen = []; $hit[$k] = array_values(array_filter($v, function ($x) use (&$seen) { $s = json_encode($x); if (isset($seen[$s])) return false; return $seen[$s] = true; }));
    }
    return ['covered' => true, 'layers' => $hit, 'city' => $city, 'citycode' => $citycode];
}

/** 判定から「いちばん大事な結論」を規則で作る（AIは使わない）。 */
function verdict(array $L): array {
    $out = [];
    $y = $L['youto'][0] ?? null;
    $sen = array_column($L['senbiki'] ?? [], 'name');
    if (in_array('市街化調整区域', $sen, true)) {
        $out[] = ['level' => 'warn', 'text' => '市街化調整区域です。市街化を抑える区域で、建物を建てるための開発や建築には原則として許可が要り、基準も厳しく決められています（都市計画法第29条・第34条）。'];
    } elseif (in_array('市街化区域', $sen, true)) {
        $out[] = ['level' => 'ok', 'text' => '市街化区域です。用途地域に合わせて建物を建てられる区域です。'];
    } elseif (!empty($L['tokei'])) {
        $out[] = ['level' => 'info', 'text' => '都市計画区域ですが、区域区分（市街化区域・市街化調整区域の線引き）は定められていません。'];
    } elseif (!empty($L['jyuntoshi'])) {
        $out[] = ['level' => 'info', 'text' => '準都市計画区域です。'];
    }
    if ($y) {
        $num = ($y['bcr'] !== '' ? '建ぺい率' . $y['bcr'] . '%' : '建ぺい率（データに記載なし）') . '・' . ($y['far'] !== '' ? '容積率' . $y['far'] . '%' : '容積率（データに記載なし）');
        $out[] = ['level' => 'ok', 'text' => '用途地域は「' . $y['name'] . '」、' . $num . 'です。'];
    } elseif (!in_array('市街化調整区域', $sen, true)) {
        $out[] = ['level' => 'info', 'text' => '用途地域の指定はありません。'];
    }
    if (!empty($L['bouka'])) $out[] = ['level' => 'info', 'text' => implode('・', array_column($L['bouka'], 'name')) . 'に入っています（建築基準法第61条の防火の制限）。'];
    if (!empty($L['douro'])) $out[] = ['level' => 'warn', 'text' => '約' . min(array_column($L['douro'], 'dist_m')) . 'm以内に都市計画道路があります。敷地が道路の区域に入る場合は、建築に許可が要ります（都市計画法第53条）。'];
    return $out;
}

function check_point(float $lon, float $lat, string $title): array {
    $pref = pref_of($title);
    $r = lookup($lon, $lat, $pref);
    $res = ['status' => 'ok', 'address' => $title, 'lat' => $lat, 'lon' => $lon, 'pref' => $pref,
            'covered' => $r['covered'], 'city' => $r['city'] ?? '', 'citycode' => $r['citycode'] ?? '',
            'layers' => $r['layers'], 'checked_at' => date('Y-m-d H:i'),
            'source' => $GLOBALS['META']['source'] ?? '', 'source_url' => $GLOBALS['META']['source_url'] ?? '', 'note' => NOTE_MLIT];
    $res['verdict'] = $r['covered'] ? verdict($r['layers']) : [['level' => 'info', 'text' => ($pref ?: 'この場所') . 'のデータは収録していません（「区域外」という意味ではありません）。']];
    if ($r['covered'] && !$r['layers']) {
        $res['verdict'] = [['level' => 'info', 'text' => 'この地点には、収録している都市計画（用途地域・区域区分など）が1つも当たりませんでした。都市計画区域の外か、この市区町村のデータが出典に載っていない可能性があります。']];
    }
    return $res;
}

function check_query(string $q): array {
    $g = geocode($q);
    if (!$g) return ['status' => 'not_found', 'query' => $q];
    $r = check_point($g['lon'], $g['lat'], $g['title']); $r['query'] = $q;
    return $r;
}

// ── 画面の部品 ─────────────────────────────────────────
function jsonld(array $a) { echo '<script type="application/ld+json">' . json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }

function head_html(string $title, string $desc, string $canon, array $crumbs = [], array $faq = []) {
    global $SITE, $OGP, $ORIGIN, $SELF, $LOGO;
    $url = $ORIGIN . $SELF . $canon;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '"><link rel="canonical" href="' . h($url) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:title" content="' . h($title) . '">'
       . '<meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . h($OGP) . '">'
       . '<meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    echo '<link rel="icon" href="https://kurage.exbridge.jp/images/kurage-mascot-cutout-300.webp">';
    jsonld(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $SITE, 'url' => $ORIGIN . $SELF . '/',
        'potentialAction' => ['@type' => 'SearchAction', 'target' => $ORIGIN . $SELF . '/check?q={search_term_string}', 'query-input' => 'required name=search_term_string']]);
    if ($crumbs) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $ORIGIN . $SELF . '/']];
        foreach ($crumbs as $i => $c) { $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $c[0], 'item' => $ORIGIN . $SELF . $c[1]]; }
        jsonld(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }
    if ($faq) {
        jsonld(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($f) {
            return ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]]; }, $faq)]);
    }
    echo '<style>'
       . ':root{--ink:#12202f;--mut:#5d6b7a;--teal:#0a9a8f;--teal-d:#087f76;--teal-l:#e6f4f2;--line:#dfe7ec;--bg:#f5f8fa;--amber:#b7791f;--amber-l:#fdf6e3;--red:#b42318;--red-l:#fdecea}'
       . '*{box-sizing:border-box}html{color-scheme:light}html,body{overflow-x:hidden;max-width:100%}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.85 "Noto Sans JP",system-ui,sans-serif}'
       . 'img,table{max-width:100%}a{color:var(--teal-d)}.wrap{width:min(960px,100% - 32px);margin:0 auto}'
       . 'header.top{background:#fff;border-bottom:1px solid var(--line)}header.top .wrap{display:flex;align-items:center;gap:12px;flex-wrap:wrap;min-height:58px}'
       . '.brand{display:flex;align-items:center;gap:8px;font-weight:800;color:var(--ink);text-decoration:none}.brand img{width:32px;height:32px;object-fit:contain}'
       . 'nav{display:flex;flex-wrap:wrap;gap:4px 14px}nav a{color:var(--mut);text-decoration:none;font-size:14px;font-weight:600}'
       . 'main{padding:20px 0 44px}h1{font-size:clamp(21px,4.2vw,30px);line-height:1.4;margin:0 0 10px;text-wrap:balance}h2{font-size:19px;margin:28px 0 10px;border-left:5px solid var(--teal);padding-left:10px}h3{font-size:16px;margin:16px 0 6px}'
       . '.lead{margin:0 0 14px}.mut{color:var(--mut)}.crumb{font-size:13px;color:var(--mut);margin-bottom:8px}.crumb a{color:var(--mut)}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin:14px 0;box-shadow:0 1px 2px rgba(18,32,47,.04)}'
       . '.hero{background:linear-gradient(135deg,#e6f4f2,#fff);border:1px solid #bfe3dd}'
       . '.form{display:flex;gap:10px;flex-wrap:wrap}.form input[type=text]{flex:1 1 260px;min-width:0;font-size:17px;padding:12px 14px;border:2px solid var(--line);border-radius:10px}'
       . '.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}'
       . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:12px}.grid>*{min-width:0}'
       . '.card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;min-width:0}.card .k{font-size:12.5px;color:var(--mut);font-weight:700}.card .v{font-size:19px;font-weight:800;line-height:1.4;overflow-wrap:anywhere}.card .s{font-size:13px;color:var(--mut);margin-top:4px}'
       . '.card.warn{background:var(--amber-l);border-color:#e6c98b}.card.none{background:#f7f9fb}'
       . '.vd{border-radius:10px;padding:10px 12px;margin:8px 0;font-weight:600}.vd.ok{background:var(--teal-l)}.vd.warn{background:var(--amber-l)}.vd.info{background:#eef2f6}'
       . 'blockquote.law{margin:10px 0;padding:10px 14px;background:#fff;border-left:4px solid var(--teal);border-radius:6px;font-size:15px}blockquote.law cite{display:block;font-style:normal;font-size:12.5px;color:var(--mut);margin-top:4px}'
       . '.note{background:var(--amber-l);border-left:4px solid var(--amber);padding:10px 12px;border-radius:8px;font-size:14px;margin:10px 0}'
       . '.src{font-size:12.5px;color:var(--mut)}.scroll-x{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}table.t{border-collapse:collapse;width:100%;font-size:14px}table.t th,table.t td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;white-space:nowrap}'
       . '.chips{display:flex;flex-wrap:wrap;gap:8px}.chips a{display:inline-block;border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;text-decoration:none;font-size:14px;color:var(--ink)}'
       . '.chat{border:2px solid #bfe3dd}.chat .log{max-height:420px;overflow-y:auto;margin:8px 0}.chat .m{padding:8px 12px;border-radius:10px;margin:6px 0;white-space:pre-wrap;overflow-wrap:anywhere}.chat .u{background:#eef2f6;margin-left:15%}.chat .a{background:var(--teal-l);margin-right:10%}'
       . '.chat textarea{width:100%;min-height:62px;font:inherit;padding:10px;border:2px solid var(--line);border-radius:10px}'
       . 'footer{border-top:1px solid var(--line);background:#fff;padding:18px 0;font-size:13px;color:var(--mut)}'
       . '</style></head><body><header class="top"><div class="wrap"><a class="brand" href="' . h($SELF . '/') . '"><img src="' . h($LOGO) . '" width="32" height="32" alt="株式会社エクスブリッジ">' . h($SITE) . '</a><nav>';
    foreach (['/' => '住所で調べる', '/yoto' => '用途地域13種', '/yogo/kenpeiritsu' => '建ぺい率', '/yogo/shigaika-chosei-kuiki' => '市街化調整区域', '/pref' => '都道府県・市区町村', '/about' => 'このサイトについて'] as $p => $t) {
        echo '<a href="' . h(u($p)) . '">' . h($t) . '</a>';
    }
    echo '</nav></div></header><main><div class="wrap">';
    if ($crumbs) {
        echo '<div class="crumb"><a href="' . h(u('/')) . '">' . h($SITE) . '</a>';
        foreach ($crumbs as $c) echo ' / <a href="' . h(u($c[1])) . '">' . h($c[0]) . '</a>';
        echo '</div>';
    }
}

function foot_html() {
    global $META, $STORE, $PROMO;
    echo '<h2>出典と注意</h2><div class="panel src"><p>都市計画: ' . h($META['source'] ?? '') . '（<a href="' . h($META['source_url'] ?? '') . '">国土交通省</a>）。'
       . '条文: e-Gov法令検索。住所の位置: 国土地理院 地名検索。</p><p>' . h(NOTE_MLIT) . '</p>'
       . '<p>判定は住所の代表点で照らした参考情報で、公的な証明ではありません。建ぺい率の角地緩和・前面道路による容積率の制限・条例による上乗せは含みません。</p></div>';
    if ($PROMO) echo '<div class="panel"><p><b>このシステムを自社サイトに置く</b>　不動産会社・設計事務所・工務店向けに、全国のデータを作り終えた一式を用意しています。PHP1ファイルとSQLiteだけで動き、用語・市区町村のページも自社サイトの集客に使えます。住所から重要事項説明の災害4項目（洪水・内水・高潮・土砂）まで確かめる仕組みもあります。</p>'
       . '<p><a class="btn" href="https://kappstore.exbridge.jp/app.php?id=4bb2a5775eaad593&amp;ref=ktoshikeikaku">都市計画ナビを自社に置く</a> <a class="btn ghost" href="' . h($STORE . '&ref=ktoshikeikaku') . '">重説 災害項目チェック</a> <a class="btn ghost" href="https://exbridge.jp/contact.php?ref=ktoshikeikaku">相談する（無料）</a></p></div>';
    echo '</div></main><footer><div class="wrap">Kurage 都市計画ナビ（ktoshikeikaku）｜株式会社エクスブリッジ（名古屋）｜<a href="' . h(u('/about')) . '">このサイトについて</a>｜<a href="' . h(u('/data')) . '">データ</a>｜<a href="' . h(u('/llms.txt')) . '">llms.txt</a></div></footer>';
    // 再販パートナー募集の枠（中身は kurage_web/partner-bar.js）。当社の公開先でだけ読む（配布版を置いたサイトからは当社へ通信しない）
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    echo '</body></html>';
}

function search_box(string $q = '', string $hint = '') {
    echo '<div class="panel hero"><form class="form" method="get" action="' . h(u('/check')) . '">'
       . '<input type="text" name="q" value="' . h($q) . '" placeholder="住所（例: 愛知県名古屋市中区三の丸3-1-1）" aria-label="住所">'
       . '<button class="btn" type="submit">調べる</button></form>'
       . '<p class="src" style="margin:8px 0 0">' . h($hint ?: '用途地域・建ぺい率・容積率・市街化調整区域・防火地域・高度地区・地区計画・居住誘導区域・近くの都市計画道路を一度に表示します（全国1,377市区町村・国土交通省の令和7年度データ）。') . '</p></div>';
}

function chat_box(string $addr = '', string $placeholder = '') {
    echo '<div class="panel chat" id="chat"><h2 style="margin-top:0">AIに聞く（住所と質問）</h2>'
       . '<p class="src">答えの中身は、住所から引いた都市計画のデータと条文だけです。AIはそれを分かりやすく言い換えます。</p>'
       . '<div class="form"><input type="text" id="chat-addr" value="' . h($addr) . '" placeholder="住所（例: 大阪府大阪市北区梅田1-1-3）" aria-label="住所"></div>'
       . '<div class="log" id="chat-log" aria-live="polite"></div>'
       . '<textarea id="chat-msg" placeholder="' . h($placeholder ?: '例: この土地に3階建ての家は建てられる？／市街化調整区域なら何ができる？') . '" aria-label="質問"></textarea>'
       . '<p style="margin:8px 0 0"><button class="btn" id="chat-send" type="button">聞く</button></p></div>';
    echo "<script>(function(){var s=document.getElementById('chat-send'),a=document.getElementById('chat-addr'),m=document.getElementById('chat-msg'),l=document.getElementById('chat-log');"
       . "function add(t,c){var d=document.createElement('div');d.className='m '+c;d.textContent=t;l.appendChild(d);l.scrollTop=l.scrollHeight;return d}"
       . "s.onclick=function(){var q=a.value.trim(),msg=m.value.trim();if(!q){a.focus();return}if(!msg){m.focus();return}add(msg,'u');m.value='';var w=add('調べています…','a');s.disabled=true;"
       . "fetch('" . u('/chat') . "',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q:q,msg:msg})}).then(function(r){return r.json()}).then(function(j){w.textContent=j.answer||j.error||'答えを作れませんでした'}).catch(function(){w.textContent='通信に失敗しました。少し待ってからもう一度どうぞ'}).finally(function(){s.disabled=false})}})();</script>";
}

function quote_block(string $key) {
    global $QUOTES;
    $q = $QUOTES[$key] ?? null;
    if (!$q) return;
    echo '<blockquote class="law">' . h($q['text']) . '<cite>' . h($q['cite']) . '（<a href="' . h($q['url']) . '">e-Gov法令検索</a>）</cite></blockquote>';
}

function result_html(array $r) {
    global $LAYER_LABEL, $LAYER_TERM;
    if (($r['status'] ?? '') === 'not_found') { echo '<div class="panel"><p>住所が見つかりませんでした。番地まで入れるか、表記を変えてお試しください。</p></div>'; return; }
    echo '<div class="panel"><p class="src">調べた場所: <b>' . h($r['address']) . '</b>（' . h(round($r['lat'], 5)) . ', ' . h(round($r['lon'], 5)) . '）' . h($r['checked_at']) . '</p>';
    foreach ($r['verdict'] as $v) echo '<div class="vd ' . h($v['level']) . '">' . h($v['text']) . '</div>';
    echo '</div>';
    if (!$r['covered']) return;
    $L = $r['layers'];
    echo '<div class="grid">';
    $order = ['youto', 'senbiki', 'tokei', 'jyuntoshi', 'bouka', 'koudoti', 'koudori', 'tkbt', 'chikukei', 'fuuchichiku', 'ritteki', 'tokuteiyouto', 'tochiku', 'kouen', 'douro', 'ryokukachiiki', 'toshisaisei', 'tokuteiyuudou', 'tokuryoku'];
    foreach ($order as $k) {
        $label = $LAYER_LABEL[$k];
        $link = isset($LAYER_TERM[$k]) ? ' <a class="src" href="' . h(u('/yogo/' . $LAYER_TERM[$k])) . '">とは</a>' : '';
        if (empty($L[$k])) {
            if (in_array($k, ['youto', 'senbiki', 'bouka', 'koudoti', 'chikukei', 'ritteki', 'douro'], true)) {
                echo '<div class="card none"><div class="k">' . h($label) . $link . '</div><div class="v">' . ($k === 'douro' ? '約20m以内になし' : '指定なし') . '</div></div>';
            }
            continue;
        }
        $vals = [];
        foreach ($L[$k] as $it) {
            $s = $it['name'];
            if ($k === 'youto') $s .= "\n建ぺい率 " . ($it['bcr'] !== '' ? $it['bcr'] . '%' : '記載なし') . '・容積率 ' . ($it['far'] !== '' ? $it['far'] . '%' : '記載なし');
            if ($k === 'douro') $s = ($it['name'] !== '都市計画道路' ? $it['name'] : '都市計画道路') . '（約' . $it['dist_m'] . 'm）';
            $vals[] = $s;
        }
        $warn = in_array($k, ['douro'], true) || ($k === 'senbiki' && in_array('市街化調整区域', array_column($L[$k], 'name'), true));
        echo '<div class="card' . ($warn ? ' warn' : '') . '"><div class="k">' . h($label) . $link . '</div><div class="v" style="white-space:pre-line">' . h(implode("\n", $vals)) . '</div></div>';
    }
    echo '</div>';
    if (!empty($r['citycode'])) echo '<p><a href="' . h(u('/city/' . $r['citycode'])) . '">' . h($r['city']) . 'の都市計画の内訳を見る</a></p>';
    echo '<p class="note">' . h(NOTE_MLIT) . '</p>';
}

// ── AIチャット（gemma4・当社の中継を通す。DeepSeek は使わない） ──
function facts_text(array $r): string {
    global $LAYER_LABEL;
    if (($r['status'] ?? '') !== 'ok') return '住所が見つからなかった。';
    $t = "住所: {$r['address']}\n";
    foreach ($r['verdict'] as $v) $t .= "結論: {$v['text']}\n";
    foreach ($r['layers'] as $k => $items) {
        foreach ($items as $it) {
            $t .= ($LAYER_LABEL[$k] ?? $k) . ': ' . $it['name'] . (isset($it['bcr']) ? "（建ぺい率{$it['bcr']}%・容積率{$it['far']}%）" : '') . (isset($it['dist_m']) ? "（約{$it['dist_m']}m）" : '') . "\n";
        }
    }
    return $t;
}

function relevant_quotes(array $r, string $msg): string {
    global $QUOTES;
    $keys = ['都市計画法:7:2', '都市計画法:7:3', '建築基準法:52:1', '建築基準法:53:1'];
    $L = $r['layers'] ?? [];
    if (!empty($L['bouka'])) $keys[] = '都市計画法:9:21';
    if (!empty($L['koudoti'])) $keys[] = '建築基準法:58:1';
    if (!empty($L['douro'])) $keys[] = '都市計画法:53:1';
    if (!empty($L['chikukei'])) $keys[] = '都市計画法:12_5:1';
    if (in_array('市街化調整区域', array_column($L['senbiki'] ?? [], 'name'), true)) { $keys[] = '都市計画法:29:1'; $keys[] = '都市計画法:34:1'; }
    if (mb_strpos($msg, '道路') !== false || mb_strpos($msg, '接') !== false) { $keys[] = '建築基準法:43:1'; $keys[] = '建築基準法:42:1'; }
    $t = '';
    foreach (array_unique($keys) as $k) { if (isset($QUOTES[$k])) $t .= "【{$QUOTES[$k]['cite']}】" . mb_substr($QUOTES[$k]['text'], 0, 220) . "\n"; }
    return $t;
}

function ask_ai(string $facts, string $law, string $msg): ?string {
    global $CFG;
    $base = (string)($CFG['relay_base'] ?? ''); $token = (string)($CFG['relay_token'] ?? '');
    if ($base === '') return null;   // トークンは Ollama 直なら要らない
    $sys = "あなたは不動産・建築の窓口担当のように、都市計画をやさしく説明する係です。\n"
         . "答えに使ってよいのは【データ】と【条文】だけです。書いていない数字・区域・条例の中身を作ってはいけません。\n"
         . "「建てられる」「できない」と断定せず、最後は市区町村の都市計画課や建築指導課で確かめるよう一言添えてください。\n"
         . "建ぺい率の角地緩和、前面道路による容積率の制限、条例の上乗せはデータに含まれていないので、関係する質問ならそのことを伝えてください。\n"
         . "言葉の意味はこのとおりで、逆にしてはいけません: 建ぺい率＝建築面積÷敷地面積、容積率＝延べ面積÷敷地面積。条文の定義を言い換え直す必要はありません。\n"
         . "日本語で、4〜6文で答えてください。";
    $body = json_encode(['model' => (string)($CFG['model'] ?? 'gemma4:12b-it-qat'), 'temperature' => 0.2, 'max_tokens' => 700, 'reasoning_effort' => 'none',
        'messages' => [['role' => 'system', 'content' => $sys],
                       ['role' => 'user', 'content' => "【データ】\n{$facts}\n【条文】\n{$law}\n【質問】\n{$msg}"]]], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 90, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\n" . ($token !== '' ? "Authorization: Bearer {$token}\r\n" : ''), 'content' => $body]]);
    $res = @file_get_contents(rtrim($base, '/') . '/chat/completions', false, $ctx);
    if ($res === false) return null;
    $j = json_decode($res, true);
    return $j['choices'][0]['message']['content'] ?? null;
}

// ── robots / sitemap / llms / api / chat ───────────────
if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nDisallow: " . u('/chat') . "\nSitemap: {$ORIGIN}{$SELF}/sitemap.xml\n"; exit; }
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $lm = date('Y-m-d', (int)@filemtime($DIR . '/main.sqlite'));
    $urls = ['/', '/yoto', '/pref', '/data', '/about'];
    foreach ($TERMS as $s => $t) $urls[] = '/yogo/' . $s;
    foreach (array_keys($PREF_NAMES) as $c) $urls[] = '/pref/' . $c;
    foreach ($db->query('SELECT citycode FROM city ORDER BY citycode') as $r) $urls[] = '/city/' . $r['citycode'];
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $p) echo '<url><loc>' . h($ORIGIN . $SELF . $p) . '</loc><lastmod>' . $lm . '</lastmod></url>';
    echo '</urlset>'; exit;
}
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    $nc = (int)$db->query('SELECT count(*) c FROM city')->fetch()['c'];
    echo "# Kurage 都市計画ナビ（ktoshikeikaku）\n\n"
       . "> 住所を入れると、その地点の用途地域・建ぺい率・容積率・市街化区域／市街化調整区域・防火地域・準防火地域・高度地区・特別用途地区・地区計画・風致地区・立地適正化計画（居住誘導区域・都市機能誘導区域）と、約20m以内の都市計画道路を一度に返す、日本全国の都市計画の参照システムです。\n\n"
       . "- データ: {$META['source']}（{$META['source_url']}）。収録 {$nc} 市区町村（47都道府県）。\n"
       . "- 条文: 都市計画法・建築基準法・都市再生特別措置法を e-Gov 法令APIから機械的に取り出して引用（言い換えない）。\n"
       . "- 判定: 結論はデータから規則で作る。AIチャット（ローカルLLM）は判定結果と条文を言い換えるだけ。\n"
       . "- 注意: 出典は「建築確認申請や不動産重要事項説明等の手続に用いることを保証するものではなく、参考情報」。角地の建ぺい率緩和・前面道路幅による容積率の制限・条例の上乗せは含まない。収録外の市区町村は「未収録」で、「区域外」ではない。\n\n"
       . "## よく混同されるもの\n- 建ぺい率（建築面積÷敷地面積）と容積率（延べ面積÷敷地面積）\n- 市街化区域（市街化を進める）と市街化調整区域（市街化を抑える）と、区域区分のない都市計画区域\n- 高度地区（都市計画で定める高さ制限。中身は市区町村ごと）と、低層住居専用地域の絶対高さ制限（建築基準法）\n\n"
       . "## ページ\n- 住所で調べる: {$ORIGIN}{$SELF}/check?q=住所\n- API(JSON): {$ORIGIN}{$SELF}/api?q=住所\n"
       . "- MCP（Streamable HTTP・認証なし）: {$ORIGIN}{$SELF}/mcp　ツール: toshikeikaku_lookup（住所→都市計画）・toshikeikaku_term（用語と条文）・toshikeikaku_city（市区町村の内訳）\n";
    foreach ($TERMS as $s => $t) echo "- {$t['name']}: {$ORIGIN}{$SELF}/yogo/{$s}\n";
    echo "- 都道府県・市区町村: {$ORIGIN}{$SELF}/pref\n";
    exit;
}
// ── MCP（AIエージェント向け・Streamable HTTP の最小実装。状態を持たず、1リクエスト=1応答のJSON） ──
// 接続: claude mcp add --transport http ktoshikeikaku https://kurage.exbridge.jp/ktoshikeikaku.php/mcp
// 中身は画面・/api と同じ関数を呼ぶだけ。新しい判定経路は作らない。
function mcp_tools(): array {
    return [
        ['name' => 'toshikeikaku_lookup',
         'description' => '日本の住所（または緯度経度）から、その地点の都市計画を返す: 用途地域・建ぺい率・容積率・市街化区域/市街化調整区域・防火地域・高度地区・地区計画・風致地区・立地適正化計画（居住誘導区域など）・約20m以内の都市計画道路。出典は国土交通省 都市計画決定GISデータ（令和7年度・全国1,377市区町村）。参考情報であり、重要事項説明や建築確認には市区町村の窓口で確認が必要。covered=false は「未収録」で、「区域外」ではない。',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'address' => ['type' => 'string', 'description' => '住所（例: 愛知県名古屋市中区三の丸3-1-1）'],
             'lat' => ['type' => 'number', 'description' => '緯度（address の代わりに使う）'],
             'lon' => ['type' => 'number', 'description' => '経度（address の代わりに使う）']]]],
        ['name' => 'toshikeikaku_term',
         'description' => '都市計画の用語（用途地域13種・建ぺい率・容積率・市街化調整区域・防火地域・高度地区・地区計画など34語）の説明と、都市計画法・建築基準法の条文の原文引用を返す。用途地域なら建築基準法 別表第二の該当項（建てられる/建ててはならない建物）と、全国の建ぺい率・容積率の分布も返す。',
         'inputSchema' => ['type' => 'object', 'properties' => ['term' => ['type' => 'string', 'description' => '用語（例: 第一種低層住居専用地域、容積率、市街化調整区域）']], 'required' => ['term']]],
        ['name' => 'toshikeikaku_city',
         'description' => '市区町村の都市計画の内訳を返す: 用途地域ごとの面積(ha)、建ぺい率・容積率の組み合わせ、市街化区域と市街化調整区域の面積。政令指定都市は市単位（例: 名古屋市）、東京23区は区単位。',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'city' => ['type' => 'string', 'description' => '市区町村名（例: 名古屋市、新宿区）か5桁の市区町村コード'],
             'pref' => ['type' => 'string', 'description' => '都道府県名（同じ名前の市区町村があるとき。例: 東京都）']], 'required' => ['city']]],
    ];
}

function mcp_call(string $name, array $a): array {
    global $TERMS, $QUOTES, $APPDX, $NAT, $db, $ORIGIN, $SELF;
    if ($name === 'toshikeikaku_lookup') {
        if (isset($a['lat'], $a['lon'])) return check_point((float)$a['lon'], (float)$a['lat'], (string)($a['address'] ?? ''));
        $q = trim((string)($a['address'] ?? ''));
        if ($q === '') throw new InvalidArgumentException('address か lat・lon を指定してください');
        return check_query(mb_substr($q, 0, 100));
    }
    if ($name === 'toshikeikaku_term') {
        $w = trim((string)($a['term'] ?? '')); $hit = null;
        foreach ($TERMS as $t) { if ($t['slug'] === $w || $t['name'] === $w) { $hit = $t; break; } }
        if (!$hit) foreach ($TERMS as $t) { if (mb_strpos($t['name'], $w) !== false || ($w !== '' && mb_strpos($w, $t['name']) !== false)) { $hit = $t; break; } }
        if (!$hit) return ['found' => false, 'terms' => array_values(array_map(function ($t) { return $t['name']; }, $TERMS))];
        $out = ['found' => true, 'name' => $hit['name'], 'url' => $ORIGIN . $SELF . '/yogo/' . $hit['slug'], 'lead' => $hit['lead'] ?? '',
                'law' => array_values(array_filter(array_map(function ($k) use ($QUOTES) { return $QUOTES[$k] ?? null; }, $hit['quotes'] ?? [])))];
        if (($hit['kind'] ?? '') === 'zone') { $out['appendix2'] = $APPDX[$hit['letter']] ?? null; $out['national'] = $NAT['youto'][$hit['name']] ?? null; }
        return $out;
    }
    if ($name === 'toshikeikaku_city') {
        $c = trim((string)($a['city'] ?? '')); $p = trim((string)($a['pref'] ?? ''));
        if (preg_match('/^\d{5}$/', $c)) { $st = $db->prepare('SELECT * FROM city WHERE citycode=?'); $st->execute([$c]); }
        else { $st = $db->prepare('SELECT * FROM city WHERE city=?' . ($p !== '' ? ' AND pref=?' : '')); $st->execute($p !== '' ? [$c, $p] : [$c]); }
        $rows = $st->fetchAll();
        if (!$rows) return ['found' => false, 'note' => '収録していない市区町村です（「区域外」という意味ではありません）'];
        if (count($rows) > 1) return ['found' => false, 'ambiguous' => array_map(function ($r) { return $r['pref'] . $r['city']; }, $rows), 'note' => 'pref を指定してください'];
        $r = $rows[0]; $s = jd($r['stats']);
        return ['found' => true, 'pref' => $r['pref'], 'city' => $r['city'], 'citycode' => $r['citycode'], 'url' => $ORIGIN . $SELF . '/city/' . $r['citycode'],
                'unit' => 'ha', 'stats' => $s, 'note' => 'youto のキーは「用途地域|建ぺい率|容積率」。国土交通省 都市計画決定GISデータ（令和7年度）を当社で集計した参考値。'];
    }
    throw new InvalidArgumentException('知らないツールです: ' . $name);
}

if ($path === '/mcp') {
    header('Content-Type: application/json; charset=UTF-8'); header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Accept');
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m === 'OPTIONS') { http_response_code(204); exit; }
    if ($m !== 'POST') {   // サーバーから押し出す通知は無いので、GET のストリームは開かない（仕様どおり 405）
        http_response_code(405); header('Allow: POST');
        echo json_encode(['error' => 'MCP（Streamable HTTP）です。POST で JSON-RPC を送ってください', 'tools' => array_column(mcp_tools(), 'name')], JSON_UNESCAPED_UNICODE); exit;
    }
    $req = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($req) || !isset($req['method'])) {
        http_response_code(400); echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'JSON-RPC として読めません']]); exit;
    }
    if (!array_key_exists('id', $req)) { http_response_code(202); exit; }   // 通知（notifications/*）には応答しない
    $id = $req['id']; $method = $req['method']; $params = (array)($req['params'] ?? []);
    $res = null; $err = null;
    try {
        if ($method === 'initialize') {
            $res = ['protocolVersion' => (string)($params['protocolVersion'] ?? '2025-06-18'), 'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'ktoshikeikaku', 'title' => 'Kurage 都市計画ナビ', 'version' => '1.0.0'],
                    'instructions' => '日本の住所から都市計画（用途地域・建ぺい率・容積率・市街化調整区域など）を調べます。結果は参考情報です。covered=false は未収録で、区域外ではありません。'];
        } elseif ($method === 'ping') { $res = new stdClass(); }
        elseif ($method === 'tools/list') { $res = ['tools' => mcp_tools()]; }
        elseif ($method === 'tools/call') {
            try {
                $out = mcp_call((string)($params['name'] ?? ''), (array)($params['arguments'] ?? []));
                $res = ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE)]], 'isError' => false];
            } catch (InvalidArgumentException $e) {
                $res = ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
            }
        } else { $err = ['code' => -32601, 'message' => 'Method not found: ' . $method]; }
    } catch (Throwable $e) { $err = ['code' => -32603, 'message' => '内部エラー']; }
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id] + ($err ? ['error' => $err] : ['result' => $res]), JSON_UNESCAPED_UNICODE); exit;
}

if ($path === '/api') {
    header('Content-Type: application/json; charset=UTF-8'); header('Access-Control-Allow-Origin: *');
    $q = trim((string)($_GET['q'] ?? ''));
    if (isset($_GET['lat'], $_GET['lon'])) { $r = check_point((float)$_GET['lon'], (float)$_GET['lat'], (string)($_GET['addr'] ?? '')); }
    elseif ($q !== '') { $r = check_query(mb_substr($q, 0, 100)); }
    else { http_response_code(400); echo json_encode(['error' => 'q（住所）か lat・lon を指定してください'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}
if ($path === '/chat') {
    header('Content-Type: application/json; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"error":"POSTで送ってください"}'; exit; }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $q = mb_substr(trim((string)($in['q'] ?? '')), 0, 100); $msg = mb_substr(trim((string)($in['msg'] ?? '')), 0, 300);
    if ($q === '' || $msg === '') { http_response_code(400); echo json_encode(['error' => '住所と質問を入れてください'], JSON_UNESCAPED_UNICODE); exit; }
    // 1つのIPから短時間に大量に来たら止める（ファイルに回数を数える）
    $ip = preg_replace('/[^0-9a-f.:]/i', '', (string)($_SERVER['REMOTE_ADDR'] ?? '')); $rf = sys_get_temp_dir() . '/ktk_rate_' . md5($ip);
    $hits = array_filter(explode(',', (string)@file_get_contents($rf)), function ($t) { return (int)$t > time() - 600; });
    if (count($hits) >= 15) { http_response_code(429); echo json_encode(['error' => '短い時間に多くの質問がありました。10分ほど待ってからどうぞ'], JSON_UNESCAPED_UNICODE); exit; }
    $hits[] = time(); @file_put_contents($rf, implode(',', $hits));
    $r = check_query($q);
    if (($r['status'] ?? '') !== 'ok') { echo json_encode(['answer' => '住所が見つかりませんでした。番地まで入れて、もう一度どうぞ。'], JSON_UNESCAPED_UNICODE); exit; }
    $facts = facts_text($r);
    $ans = ask_ai($facts, relevant_quotes($r, $msg), $msg);
    if ($ans === null) {   // AIが止まっていても、データの結論は必ず返す
        $ans = implode("\n", array_column($r['verdict'], 'text')) . "\n（AIの説明は今つながりません。上の結論はデータから出したものです。）";
    }
    echo json_encode(['answer' => trim($ans) . "\n\n※ 参考情報です。最後は市区町村の窓口で確かめてください。", 'address' => $r['address']], JSON_UNESCAPED_UNICODE); exit;
}

// ── ページ ─────────────────────────────────────────────
function term_body(array $t) {
    global $NAT, $APPDX, $TERMS;
    echo '<p class="lead"><b>' . h($t['lead']) . '</b></p>';
    foreach ($t['quotes'] ?? [] as $k) quote_block($k);
    if (($t['kind'] ?? '') === 'zone') {
        $letter = $t['letter']; $ap = $APPDX[$letter] ?? null;
        $st = $NAT['youto'][$t['name']] ?? null;
        if ($st) {
            echo '<h2>全国の' . h($t['name']) . 'の建ぺい率・容積率</h2><p>国土交通省の都市計画決定GISデータ（令和7年度）では、全国' . n($st['cities']) . '市区町村に、合わせて約' . n($st['ha']) . 'ヘクタール定められています。面積の多い組み合わせは次のとおりです。</p>';
            echo '<div class="scroll-x"><table class="t"><tr><th>建ぺい率</th><th>容積率</th><th>面積の割合</th></tr>';
            foreach ($st['combos'] as $c) echo '<tr><td>' . ($c['bcr'] !== '' ? h($c['bcr']) . '%' : 'データに記載なし') . '</td><td>' . ($c['far'] !== '' ? h($c['far']) . '%' : 'データに記載なし') . '</td><td>' . h($c['share']) . '%</td></tr>';
            echo '</table></div><p class="src">数字は市区町村の都市計画で決まります。自分の土地の値は、住所で調べてください。</p>';
        }
        if ($ap) {
            $allow = mb_strpos($ap['title'], 'できる') !== false;
            echo '<h2>' . h($t['name']) . 'に' . ($allow ? '建てられる建物' : '建ててはならない建物') . '（建築基準法 別表第二（' . h($letter) . '）項）</h2>';
            echo '<p>' . ($allow ? 'この地域では、次に掲げる建物<b>以外は建てられません</b>（建築基準法第48条）。' : 'この地域では、次に掲げる建物<b>を建ててはなりません</b>（建築基準法第48条）。ここに無い建物は、ほかの制限の範囲で建てられます。') . '条文のまま引用します。</p>';
            echo '<div class="panel" style="font-size:15px"><p class="src">' . h($ap['title']) . '</p><ol style="list-style:none;padding-left:0;margin:0">';
            foreach ($ap['items'] as $it) echo '<li style="margin:4px 0">' . h($it) . '</li>';
            echo '</ol></div><p class="src">「政令で定めるもの」の中身は建築基準法施行令にあります。例外の許可（第48条各項のただし書）もあるので、個別の建物は特定行政庁に確かめてください。</p>';
        }
    }
    foreach ($t['sections'] ?? [] as $sec) {
        echo '<h2>' . h($sec[0]) . '</h2>';
        $b = $sec[1];
        if ($b === '@zones') {
            echo '<div class="grid">';
            foreach ($TERMS as $z) { if (($z['kind'] ?? '') !== 'zone') continue;
                echo '<a class="card" style="text-decoration:none;color:inherit" href="' . h(u('/yogo/' . $z['slug'])) . '"><div class="k">第' . h($z['para']) . '項</div><div class="v" style="font-size:16px">' . h($z['name']) . '</div></a>'; }
            echo '</div>';
        } elseif ($b === '@kenpei_table' || $b === '@yoseki_table') {
            echo '<div class="scroll-x"><table class="t"><tr><th>用途地域</th><th>面積の多い組み合わせ（建ぺい率/容積率）</th></tr>';
            foreach ($TERMS as $z) { if (($z['kind'] ?? '') !== 'zone') continue; $st = $NAT['youto'][$z['name']] ?? null; if (!$st) continue;
                $cs = array_slice(array_values(array_filter($st['combos'], function ($c) { return $c['bcr'] !== ''; })), 0, 3);
                echo '<tr><td><a href="' . h(u('/yogo/' . $z['slug'])) . '">' . h($z['name']) . '</a></td><td>' . h(implode('、', array_map(function ($c) { return $c['bcr'] . '/' . $c['far'] . '（' . $c['share'] . '%）'; }, $cs))) . '</td></tr>'; }
            echo '</table></div><p class="src">全国の都市計画決定GISデータ（令和7年度）の面積の割合。自分の土地の値は住所で調べてください。</p>';
        } elseif (in_array($b, ['@senbiki_stat', '@boka_stat', '@ritteki_stat'], true)) {
            $layer = ['@senbiki_stat' => 'senbiki', '@boka_stat' => 'bouka', '@ritteki_stat' => 'ritteki'][$b];
            echo '<ul>';
            foreach (($NAT['layers'][$layer] ?? []) as $name => $v) echo '<li>' . h($name) . ': 約' . n($v['ha']) . 'ヘクタール（' . n($v['cities']) . '市区町村）</li>';
            echo '</ul><p class="src">国土交通省 都市計画決定GISデータ（令和7年度）を当社で集計。データの無い市区町村は含みません。</p>';
        } else {
            echo '<p>' . h($b) . '</p>';
        }
    }
    if (($t['kind'] ?? '') === 'howto') {
        echo '<h2>いちばん早い調べ方：住所を入れる</h2><p>上の欄に住所を入れると、' . h($t['focus']) . 'を含む都市計画を、国土交通省の都市計画決定GISデータから表示します。全国1,377市区町村に対応しています。</p>'
           . '<h2>正式に確かめる方法</h2><ol><li>市区町村のサイトで公開されている都市計画図（用途地域図）を見る</li><li>市区町村の都市計画課の窓口で、都市計画図や都市計画図書を閲覧する（電話で答えてくれる自治体もあります）</li><li>重要事項説明や建築確認に使うときは、必ず窓口で最新の決定を確かめる</li></ol>'
           . '<p class="note">このシステムは当たりを付けるための参考情報です。都市計画は変更されることがあり、データの時点（令和7年度）と最新の決定が違う場合があります。</p>';
    }
}

if ($path === '') {
    $faq = [['用途地域はどうやって調べますか？', '住所の欄に住所を入れると、国土交通省の都市計画決定GISデータから、その地点の用途地域と建ぺい率・容積率を表示します。正式には市区町村の都市計画課で確かめてください。'],
            ['市街化調整区域かどうかも分かりますか？', '分かります。市街化区域・市街化調整区域・区域区分のない都市計画区域のどれに当たるかを表示します。'],
            ['全国どこでも調べられますか？', '47都道府県の1,377市区町村のデータを収録しています。データの無い市区町村は「未収録」と表示し、「区域外」とは書きません。']];
    head_html('用途地域・建ぺい率・容積率を住所で調べる｜全国の都市計画', '住所を入れるだけで、用途地域・建ぺい率・容積率・市街化調整区域・防火地域・高度地区・地区計画・居住誘導区域・近くの都市計画道路を一度に表示。全国1,377市区町村、国土交通省の都市計画決定GISデータと条文の引用つき。AIにも質問できます。', '/', [], $faq);
    echo '<h1>用途地域・建ぺい率・容積率を、住所で調べる</h1><p class="lead">住所を入れると、その場所の都市計画を一度に表示します。市区町村の都市計画図を拡大して境目を探す前に、まずここで当たりを付けてください。</p>';
    search_box();
    chat_box();
    echo '<h2>よく調べられている言葉</h2><div class="chips">';
    foreach ($TERMS as $s => $t) { if (($t['kind'] ?? '') === 'term') echo '<a href="' . h(u('/yogo/' . $s)) . '">' . h($t['name']) . '</a>'; }
    echo '</div><h2>調べ方</h2><div class="chips">';
    foreach ($TERMS as $s => $t) { if (($t['kind'] ?? '') === 'howto') echo '<a href="' . h(u('/yogo/' . $s)) . '">' . h($t['name']) . '</a>'; }
    echo '</div><h2>用途地域13種</h2><div class="chips">';
    foreach ($TERMS as $s => $t) { if (($t['kind'] ?? '') === 'zone') echo '<a href="' . h(u('/yogo/' . $s)) . '">' . h($t['name']) . '</a>'; }
    echo '</div><h2>都道府県から探す</h2><div class="chips">';
    foreach ($PREF_NAMES as $c => $p) echo '<a href="' . h(u('/pref/' . $c)) . '">' . h($p) . '</a>';
    echo '</div><h2>よくある質問</h2>';
    foreach ($faq as $f) echo '<div class="panel"><h3 style="margin-top:0">' . h($f[0]) . '</h3><p style="margin:0">' . h($f[1]) . '</p></div>';
    foot_html(); exit;
}

if ($path === '/check') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (isset($_GET['lat'], $_GET['lon'])) { $r = check_point((float)$_GET['lon'], (float)$_GET['lat'], (string)($_GET['addr'] ?? '指定した地点')); $q = $r['address']; }
    elseif ($q !== '') { $r = check_query(mb_substr($q, 0, 100)); }
    else { header('Location: ' . u('/')); exit; }
    header('X-Robots-Tag: noindex');
    head_html(($q !== '' ? $q . 'の' : '') . '用途地域・都市計画', '住所から調べた用途地域・建ぺい率・容積率・区域区分などの結果です。', '/', [['住所で調べる', '/']]);
    echo '<h1>' . h($q) . 'の都市計画</h1>';
    search_box($q);
    result_html($r);
    // 用途地域を調べた人の次の関心は「この土地は災害に強いか」。同じ住所のまま当社の防災システムへ渡す（2026-10-07）。
    // 検索から来た人の3人に1人がこの画面まで来るのに、次に進む導線が無かった。自社に置くとき（promo=false）は出さない。
    if ($PROMO && $q !== '') {
        $hz = function ($p, $path = '/') use ($q) { return 'https://kurage.exbridge.jp/' . $p . '.php' . $path . '?q=' . rawurlencode($q) . '&ref=ktoshikeikaku-check'; };
        echo '<h2>この住所の災害リスクも確かめる</h2><div class="panel">'
           . '<p style="margin-top:0">同じ住所のまま、国や自治体の公開データで調べられます（無料・登録不要）。</p><div class="chips">'
           . '<a href="' . h($hz('kbousai')) . '">まとめて見る（洪水・土砂・津波・避難所）</a>'
           . '<a href="' . h($hz('kflood')) . '">洪水・内水の浸水</a>'
           . '<a href="' . h($hz('khazard')) . '">土砂災害警戒区域</a>'
           . '<a href="' . h($hz('ktsunami')) . '">津波の浸水</a>'
           . '<a href="' . h($hz('kriskarea')) . '">災害危険区域（建築制限）</a>'
           . '</div><p style="margin-bottom:0">不動産の売買・賃貸で重要事項説明をする方へ：災害4項目（洪水・内水・高潮・土砂）を住所からまとめて確かめる'
           . ' <a href="' . h($hz('kflood', '/juyo')) . '">重説 災害項目チェック</a>（<a href="' . h($STORE . '&ref=ktoshikeikaku-check') . '">自社に置く</a>）</p></div>';
    }
    chat_box($q, 'この場所について質問（例: 3階建ては建てられる？ お店は開ける？）');
    foot_html(); exit;
}

if (preg_match('#^/yogo/([a-z0-9-]+)$#', $path, $m) && isset($TERMS[$m[1]])) {
    $t = $TERMS[$m[1]];
    if (!isset($t['lead'])) $t['lead'] = $t['name'] . 'は、住所を入れるだけで分かります。' . $t['focus'] . 'を、国土交通省の都市計画決定GISデータ（令和7年度・全国1,377市区町村）から表示します。正式な確認の仕方もまとめました。';
    $faq = $t['faq'] ?? [];
    $title = $t['title'];
    $desc = mb_substr($t['lead'], 0, 110);
    $parent = ($t['kind'] === 'zone') ? [['用途地域13種', '/yoto']] : [];
    head_html($title, $desc, '/yogo/' . $t['slug'], array_merge($parent, [[$t['name'], '/yogo/' . $t['slug']]]), $faq);
    jsonld(['@context' => 'https://schema.org', '@type' => 'DefinedTerm', 'name' => $t['name'], 'description' => $t['lead'],
        'inDefinedTermSet' => $ORIGIN . $SELF . '/', 'url' => $ORIGIN . $SELF . '/yogo/' . $t['slug']]);
    echo '<h1>' . h($t['kind'] === 'howto' ? $t['title'] : $t['name'] . 'とは') . '</h1>';
    search_box('', $t['kind'] === 'howto' ? '住所を入れると、' . $t['focus'] . 'をすぐ表示します。' : '');
    term_body($t);
    chat_box('', $t['name'] . 'について質問（例: この住所は' . $t['name'] . 'に入っている？）');
    if ($faq) { echo '<h2>よくある質問</h2>'; foreach ($faq as $f) echo '<div class="panel"><h3 style="margin-top:0">' . h($f[0]) . '</h3><p style="margin:0">' . h($f[1]) . '</p></div>'; }
    if (!empty($t['related'])) { echo '<h2>あわせて読む</h2><div class="chips">'; foreach ($t['related'] as $s) { if (isset($TERMS[$s])) echo '<a href="' . h(u('/yogo/' . $s)) . '">' . h($TERMS[$s]['name']) . '</a>'; } echo '</div>'; }
    foot_html(); exit;
}

if ($path === '/yoto') {
    head_html('用途地域13種類の一覧｜建てられる建物と建ぺい率・容積率', '用途地域13種類（住居系8・商業系2・工業系3）の定義を都市計画法第9条から引用し、建てられる建物（建築基準法別表第二）と、全国データでの建ぺい率・容積率を一覧にしました。住所から調べることもできます。', '/yoto', [['用途地域13種', '/yoto']]);
    echo '<h1>用途地域13種類の一覧</h1>';
    search_box();
    echo '<div class="scroll-x"><table class="t"><tr><th>用途地域</th><th>全国で多い建ぺい率/容積率</th><th>面積</th></tr>';
    foreach ($TERMS as $s => $t) { if (($t['kind'] ?? '') !== 'zone') continue; $st = $NAT['youto'][$t['name']] ?? null;
        $c = $st ? (array_values(array_filter($st['combos'], function ($x) { return $x['bcr'] !== ''; }))[0] ?? null) : null;
        echo '<tr><td><a href="' . h(u('/yogo/' . $s)) . '">' . h($t['name']) . '</a></td><td>' . ($c ? h($c['bcr'] . '/' . $c['far']) : '—') . '</td><td>' . ($st ? '約' . n($st['ha']) . 'ha' : '—') . '</td></tr>'; }
    echo '</table></div>';
    foreach ($TERMS as $s => $t) { if (($t['kind'] ?? '') !== 'zone') continue; echo '<h2><a href="' . h(u('/yogo/' . $s)) . '">' . h($t['name']) . '</a></h2>'; quote_block('都市計画法:9:' . $t['para']); }
    foot_html(); exit;
}

if ($path === '/pref') {
    head_html('都道府県・市区町村の用途地域と都市計画', '全国47都道府県・1,377市区町村の用途地域と都市計画の内訳。市区町村を選ぶと、用途地域ごとの面積、市街化区域と市街化調整区域の割合、建ぺい率・容積率の組み合わせを表示します。', '/pref', [['都道府県・市区町村', '/pref']]);
    echo '<h1>都道府県・市区町村から探す</h1>';
    search_box();
    $cnt = []; foreach ($db->query('SELECT pref, count(*) c FROM city GROUP BY pref') as $r) $cnt[$r['pref']] = $r['c'];
    echo '<div class="grid">';
    foreach ($PREF_NAMES as $c => $p) echo '<a class="card" style="text-decoration:none;color:inherit" href="' . h(u('/pref/' . $c)) . '"><div class="v" style="font-size:17px">' . h($p) . '</div><div class="s">' . n($cnt[$p] ?? 0) . '市区町村</div></a>';
    echo '</div>';
    foot_html(); exit;
}

if (preg_match('#^/pref/(\d{2})$#', $path, $m) && isset($PREF_NAMES[$m[1]])) {
    $p = $PREF_NAMES[$m[1]];
    $st = $db->prepare('SELECT citycode, city, stats FROM city WHERE pref=? ORDER BY citycode'); $st->execute([$p]); $cities = $st->fetchAll();
    head_html($p . 'の用途地域・都市計画｜市区町村別', $p . 'の' . count($cities) . '市区町村の用途地域と都市計画。市区町村ごとの用途地域の面積、市街化区域・市街化調整区域の割合、建ぺい率・容積率を国土交通省のデータから表示。住所で調べることもできます。', '/pref/' . $m[1], [[$p, '/pref/' . $m[1]]]);
    echo '<h1>' . h($p) . 'の用途地域・都市計画</h1>';
    search_box('', $p . 'の住所を入れると、用途地域・建ぺい率・容積率などを表示します。');
    echo '<div class="scroll-x"><table class="t"><tr><th>市区町村</th><th>市街化調整区域の割合</th><th>いちばん広い用途地域</th></tr>';
    foreach ($cities as $c) {
        $s = jd($c['stats']); $sb = $s['senbiki'] ?? []; $tot = array_sum($sb); $ch = $sb['市街化調整区域'] ?? 0;
        $zones = []; foreach (($s['youto'] ?? []) as $k => $ha) { $nm = explode('|', $k)[0]; $zones[$nm] = ($zones[$nm] ?? 0) + $ha; } arsort($zones);
        echo '<tr><td><a href="' . h(u('/city/' . $c['citycode'])) . '">' . h($c['city']) . '</a></td><td>' . ($tot > 0 ? round(100 * $ch / $tot) . '%' : '区域区分なし') . '</td><td>' . h($zones ? array_key_first($zones) : '—') . '</td></tr>';
    }
    echo '</table></div>';
    foot_html(); exit;
}

if (preg_match('#^/city/(\d{5})$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM city WHERE citycode=?'); $st->execute([$m[1]]); $c = $st->fetch();
    if (!$c) { http_response_code(404); head_html('見つかりません', '指定の市区町村は収録していません。', '/pref'); echo '<h1>この市区町村は収録していません</h1><p><a href="' . h(u('/pref')) . '">都道府県から探す</a></p>'; foot_html(); exit; }
    $s = jd($c['stats']); $city = $c['city']; $pref = $c['pref']; $pc = substr($m[1], 0, 2);
    $zones = []; foreach (($s['youto'] ?? []) as $k => $ha) { $nm = explode('|', $k)[0]; $zones[$nm] = ($zones[$nm] ?? 0) + $ha; } arsort($zones);
    $ztot = array_sum($zones); $sb = $s['senbiki'] ?? []; $sbt = array_sum($sb);
    $combos = $s['youto'] ?? []; arsort($combos);
    $top = $zones ? array_key_first($zones) : '';
    $desc = $city . '（' . $pref . '）の用途地域と都市計画。' . ($top ? 'いちばん広い用途地域は' . $top . '。' : '') . ($sbt > 0 ? '市街化調整区域は' . round(100 * ($sb['市街化調整区域'] ?? 0) / $sbt) . '%。' : '') . '建ぺい率・容積率の組み合わせと、住所での調べ方を国土交通省のデータで。';
    $faq = [[$city . 'の用途地域はどうやって調べますか？', 'このページの住所の欄に' . $city . 'の住所を入れると、用途地域と建ぺい率・容積率を表示します。正式には' . $city . 'の都市計画課の窓口や、' . $city . 'が公開している都市計画図で確かめてください。'],
            [$city . 'に市街化調整区域はありますか？', $sbt > 0 ? ('あります。国土交通省のデータでは、区域区分が定められた範囲のうち約' . round(100 * ($sb['市街化調整区域'] ?? 0) / $sbt) . '%が市街化調整区域です。') : ('国土交通省のデータには、' . $city . 'の区域区分（市街化区域・市街化調整区域）は載っていません。区域区分のない都市計画区域か、データが未整備の可能性があります。')]];
    $dup = $db->prepare('SELECT count(*) c FROM city WHERE city=?'); $dup->execute([$city]);
    $cname = (int)$dup->fetch()['c'] > 1 ? $city . '（' . $pref . '）' : $city;   // 府中市・伊達市のように同じ名前がある
    head_html($cname . 'の用途地域・都市計画図｜建ぺい率・容積率', $desc, '/city/' . $m[1], [[$pref, '/pref/' . $pc], [$city, '/city/' . $m[1]]], $faq);
    echo '<h1>' . h($city) . 'の用途地域・都市計画</h1><p class="lead">' . h($pref . $city) . 'の都市計画を、国土交通省の都市計画決定GISデータ（令和7年度）から集計しました。住所を入れると、その地点の用途地域・建ぺい率・容積率がすぐ分かります。</p>';
    search_box('', $city . 'の住所を入れてください（例: ' . $pref . $city . '…）');
    if ($zones) {
        echo '<h2>' . h($city) . 'の用途地域の内訳（面積）</h2><div class="scroll-x"><table class="t"><tr><th>用途地域</th><th>面積</th><th>割合</th></tr>';
        foreach ($zones as $nm => $ha) { $slug = ''; foreach ($TERMS as $ts => $tt) { if (($tt['name'] ?? '') === $nm) $slug = $ts; }
            echo '<tr><td>' . ($slug ? '<a href="' . h(u('/yogo/' . $slug)) . '">' . h($nm) . '</a>' : h($nm)) . '</td><td>約' . n($ha) . 'ha</td><td>' . round(100 * $ha / max($ztot, 1), 1) . '%</td></tr>'; }
        echo '</table></div>';
        echo '<h2>建ぺい率・容積率の組み合わせ（面積の多い順）</h2><div class="scroll-x"><table class="t"><tr><th>用途地域</th><th>建ぺい率</th><th>容積率</th><th>面積</th></tr>';
        foreach (array_slice($combos, 0, 12, true) as $k => $ha) { [$nm, $b, $f] = explode('|', $k);
            echo '<tr><td>' . h($nm) . '</td><td>' . ($b !== '' ? h($b) . '%' : '記載なし') . '</td><td>' . ($f !== '' ? h($f) . '%' : '記載なし') . '</td><td>約' . n($ha) . 'ha</td></tr>'; }
        echo '</table></div>';
    } else {
        echo '<div class="panel"><p>国土交通省のデータには、' . h($city) . 'の用途地域は載っていません。用途地域が定められていないか、データが未整備の可能性があります。</p></div>';
    }
    if ($sbt > 0) {
        echo '<h2>市街化区域と市街化調整区域</h2><div class="grid">';
        foreach ($sb as $nm => $ha) echo '<div class="card' . ($nm === '市街化調整区域' ? ' warn' : '') . '"><div class="k">' . h($nm) . '</div><div class="v">約' . n($ha) . 'ha</div><div class="s">' . round(100 * $ha / $sbt) . '%</div></div>';
        echo '</div><p><a href="' . h(u('/yogo/shigaika-chosei-kuiki')) . '">市街化調整区域とは</a></p>';
    }
    $others = [];
    foreach (['bouka', 'koudoti', 'chikukei', 'fuuchichiku', 'ritteki', 'tkbt'] as $k) { if (!empty($s[$k])) $others[$k] = $s[$k]; }
    if ($others) {
        echo '<h2>そのほかの都市計画</h2><div class="grid">';
        foreach ($others as $k => $d) { arsort($d); echo '<div class="card"><div class="k">' . h($LAYER_LABEL[$k]) . (isset($LAYER_TERM[$k]) ? ' <a class="src" href="' . h(u('/yogo/' . $LAYER_TERM[$k])) . '">とは</a>' : '') . '</div><div class="s" style="font-size:14px;color:var(--ink)">'
            . h(implode('、', array_map(function ($nm, $ha) { return $nm . ' 約' . number_format($ha) . 'ha'; }, array_keys(array_slice($d, 0, 4, true)), array_slice($d, 0, 4, true)))) . '</div></div>'; }
        echo '</div>';
    }
    $lay = jd($c['layers']);
    if (!empty($lay['douro'])) echo '<p>都市計画道路のデータ: ' . n($lay['douro']) . '件。住所で調べると、約20m以内に都市計画道路があるかを表示します。<a href="' . h(u('/yogo/toshikeikaku-doro')) . '">都市計画道路とは</a></p>';
    chat_box($pref . $city, $city . 'の住所について質問（例: この土地は市街化調整区域？ 建ぺい率は？）');
    echo '<h2>よくある質問</h2>'; foreach ($faq as $f) echo '<div class="panel"><h3 style="margin-top:0">' . h($f[0]) . '</h3><p style="margin:0">' . h($f[1]) . '</p></div>';
    // 同じ都道府県の市区町村へ
    $sib = $db->prepare('SELECT citycode, city FROM city WHERE pref=? AND citycode<>? ORDER BY citycode LIMIT 60'); $sib->execute([$pref, $m[1]]);
    echo '<h2>' . h($pref) . 'のほかの市区町村</h2><div class="chips">'; foreach ($sib as $r) echo '<a href="' . h(u('/city/' . $r['citycode'])) . '">' . h($r['city']) . '</a>'; echo '</div>';
    foot_html(); exit;
}

if ($path === '/data') {
    $nc = (int)$db->query('SELECT count(*) c FROM city')->fetch()['c'];
    head_html('収録データと時点｜Kurage 都市計画ナビ', '国土交通省 都市局「都市計画決定GISデータ（全国）」令和7年度版を、47都道府県・' . $nc . '市区町村分収録。用途地域・区域区分・防火地域・高度地区・地区計画・立地適正化計画・都市計画道路などのレイヤーと、条文の出典。', '/data', [['データ', '/data']]);
    jsonld(['@context' => 'https://schema.org', '@type' => 'Dataset', 'name' => '都市計画決定GISデータ（全国）令和7年度版の住所検索', 'creator' => ['@type' => 'Organization', 'name' => '国土交通省 都市局'],
        'url' => $META['source_url'] ?? '', 'spatialCoverage' => '日本', 'description' => '用途地域・区域区分・防火地域・高度地区・地区計画などの都市計画を、住所から引けるようにしたもの']);
    echo '<h1>収録データと時点</h1><div class="panel"><p>' . h($META['source'] ?? '') . '<br><a href="' . h($META['source_url'] ?? '') . '">' . h($META['source_url'] ?? '') . '</a></p><p>収録: 47都道府県・' . n($nc) . '市区町村。データの無い市区町村は「未収録」と表示します。</p><p>' . h(NOTE_MLIT) . '</p>'
       . '<p>国土数値情報の用途地域（A29）は使っていません。市区町村ごとに利用条件が違い、1,213自治体のうち201（16.6%）が有償利用不可・公開不可・回答なしのためです。</p>'
       . '<p>条文: 都市計画法・建築基準法・都市再生特別措置法（e-Gov法令API）。本文から機械的に取り出して引用しています。</p></div>';
    foot_html(); exit;
}

if ($path === '/about') {
    $faq = [['このシステムの判定は重要事項説明に使えますか？', 'そのままでは使えません。出典の国土交通省も参考情報としての利用を想定しています。当たりを付けたあと、必ず市区町村の窓口で最新の都市計画を確かめてください。'],
            ['AIチャットは何をしていますか？', '住所から引いた都市計画のデータと条文の引用だけを材料に、質問に合わせて言い換えます。データに無いことは答えず、最後は窓口で確かめるよう添えます。AIは当社のサーバーで動くモデルで、外部のAIサービスには送りません。']];
    head_html('このサイトについて｜Kurage 都市計画ナビ', 'Kurage 都市計画ナビは、住所から用途地域・建ぺい率・容積率などの都市計画を調べる参照システムです。何をして何をしないか、データと条文の出典、AIチャットの仕組みを説明します。', '/about', [['このサイトについて', '/about']], $faq);
    echo '<h1>このサイトについて</h1><div class="panel"><p>Kurage 都市計画ナビは、住所を入れると、その地点の都市計画（用途地域・建ぺい率・容積率・市街化区域／市街化調整区域・防火地域・高度地区・地区計画・風致地区・居住誘導区域など）を一度に表示する参照システムです。株式会社エクスブリッジ（名古屋）が作っています。</p>'
       . '<h3>しないこと</h3><ul><li>建てられる・建てられないを断定しません</li><li>条例の中身を要約・解釈しません</li><li>収録していない市区町村を「区域外」と書きません（「未収録」）</li><li>角地の建ぺい率緩和・前面道路による容積率の制限・条例の上乗せは判定に含みません</li></ul>'
       . '<h3>API</h3><p><code>' . h($ORIGIN . $SELF) . '/api?q=住所</code> で同じ結果を JSON で返します。</p>'
       . '<h3>MCP（AIエージェントから使う）</h3><p>Claude Code・Claude Desktop・Codex などのAIエージェントから、住所の都市計画・用語と条文・市区町村の内訳を直接引けます。認証は要りません。</p>'
       . '<div class="scroll-x"><pre style="background:#f5f8fa;border:1px solid var(--line);border-radius:8px;padding:10px;font-size:13px">claude mcp add --transport http ktoshikeikaku ' . h($ORIGIN . $SELF) . '/mcp</pre></div>'
       . '<p class="src">ツール: toshikeikaku_lookup（住所→都市計画）／toshikeikaku_term（用語と条文の原文）／toshikeikaku_city（市区町村の用途地域の面積など）。ソース: <a href="https://github.com/katsushi2441/ktoshikeikaku">GitHub</a></p></div>';
    echo '<h2>よくある質問</h2>'; foreach ($faq as $f) echo '<div class="panel"><h3 style="margin-top:0">' . h($f[0]) . '</h3><p style="margin:0">' . h($f[1]) . '</p></div>';
    foot_html(); exit;
}

http_response_code(404);
head_html('ページが見つかりません', 'お探しのページは見つかりませんでした。住所から用途地域を調べられます。', '/');
echo '<h1>ページが見つかりません</h1>'; search_box(); foot_html();
