<?php
// ktoshikeikaku_config.php にコピーして使う。無くても動く（AIチャットは判定結果だけを返す）。
return [
    // AIチャット: OpenAI 互換の窓口（Ollama なら http://<host>:11434/v1）。空ならAIを使わない
    'relay_base'  => '',
    'relay_token' => '',
    'model'       => 'gemma4:12b-it-qat',
    // 当社の商品案内をフッターに出さない
    'promo'       => false,
    // 公開URL（省略すると、アクセスされたホスト名とファイル名から作る）
    // 'origin' => 'https://example.co.jp',
    // 'self'   => '/ktoshikeikaku.php',
    // 'ogp'    => 'https://example.co.jp/images/ogp/ktoshikeikaku.png',
];
