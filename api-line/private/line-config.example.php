<?php
/**
 * LINE受信用の設定の見本。
 *
 * この見本をコピーして、Xserverの **public_html の外** へ置く。
 *   /home/<アカウント>/relagarden.jp/private/line-config.php
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
    // アプリが受信箱を読むときに使う。
    // **人が考えた言葉を使わないこと。** 次のどちらかで作った
    // 64文字以上のでたらめな文字列を貼る。
    //
    //   openssl rand -hex 32
    //   head -c 32 /dev/urandom | xxd -p -c 64
    //
    // 掲載用のPAT・Xserverの管理パスワードとは必ず別物にすること。
    // 作った値はiPhoneアプリの設定「公式LINEの受信」へ入れる。
    'inbox_token' => 'ここへ openssl rand -hex 32 の出力（64文字）を貼る',

    // ── 保存場所 ──────────────────────────────────────────────
    // 届いた問い合わせの置き場所。**public_html の外にすること。**
    // 公開領域（public_html / htdocs / www / public）の下を指していると、
    // 起動を断って「ただいま準備中です」を返す。
    // 作れない・読めない・書けない場所でも起動しない
    // （黙って問い合わせを捨てないため）。
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

    // ── 通信 ─────────────────────────────────────────────────
    // 本番は必ず true。HTTPS以外の接続を受け付けない。
    // 手元で確かめるときだけ false にする（本番の設定では変えないこと）。
    'require_https' => true,

    // /api/line/sync で受け取る上限
    'max_sync_bytes' => 64 * 1024,
    'max_sync_ids' => 200,
    'max_id_length' => 128,
];
