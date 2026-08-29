<?php
/**
 * LINE受信用の設定の見本。
 *
 * この見本をコピーして、Xserverの **public_html の外** へ置く。
 *   /home/xs674757/relagarden.jp/private/line-config.php
 *
 * 本物の line-config.php は絶対にGitへ入れない（.gitignore 済み）。
 * ここに書く値は、GitHubやXserverの管理パスワードではなく、
 * LINE Developersで発行するこの用途だけの値にすること。
 */

return [
    // ── LINE Developers（Messaging API チャネル）─────────────
    // チャネル基本設定の「チャネルシークレット」。
    // 届いた内容が本当にLINEからかを確かめるために使う。
    'channel_secret' => 'ここへチャネルシークレットを貼る',

    // 「チャネルアクセストークン（長期）」。
    // 使うのはお客様の表示名を読む1か所だけ。メッセージは送らない。
    // 空のままでもよい（そのときは表示名が空欄で届く）。
    'channel_access_token' => '',

    // ── iPhoneアプリとの合言葉 ────────────────────────────────
    // アプリが受信箱を読むときに使う。24文字以上のでたらめな文字列。
    // 作り方の例： openssl rand -hex 24
    // 掲載用のPATとは別物にすること。
    'inbox_token' => 'ここへ24文字以上のでたらめな文字列',

    // ── 保存場所 ──────────────────────────────────────────────
    // 届いた問い合わせの置き場所。public_html の外にすること。
    'storage_dir' => __DIR__ . '/line-storage',

    // ── 制限 ─────────────────────────────────────────────────
    // 受け取り済みの控えを残す日数（取り込めていないものは消さない）
    'keep_days' => 30,
    // 1回の受信で返す最大件数
    'inbox_limit' => 50,
    // 受信箱を読める回数（同じ回線から、この秒数の間に）
    'rate_window_seconds' => 3600,
    'rate_max_inbox' => 240,
    // Webhookの本文の上限（バイト）
    'max_body_bytes' => 512 * 1024,
    // 表示名を読みに行くときの待ち時間（秒）
    'profile_timeout' => 5,
];
