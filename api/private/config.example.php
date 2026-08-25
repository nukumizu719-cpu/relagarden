<?php
/**
 * 本番用の設定の見本。
 *
 * この見本をコピーして、Xserverの **public_html の外** へ置く。
 *   /home/xs674757/relagarden.jp/private/config.php
 *
 * 本物の config.php は絶対にGitへ入れない（.gitignore 済み）。
 * ここに書く値は、GitHubやXserverの管理パスワードではなく、
 * この用途だけの限定キーにすること。
 */

return [
    // ── GitHub ───────────────────────────────────────────────
    // Fine-grained personal access token
    //   対象リポジトリ: nukumizu719-cpu/relagarden のみ
    //   権限: Contents = Read and write だけ
    // 期限が切れたらここを差し替える。
    'github_token' => 'ここへFine-grained PATを貼る',
    'github_owner' => 'nukumizu719-cpu',
    'github_repo'  => 'relagarden',
    'github_branch' => 'main',

    // ── 端末の連携 ────────────────────────────────────────────
    // iPhoneアプリを初回に連携させるための合言葉。
    // 谷口さんへ口頭かLINEで伝える。定期的に変えてよい。
    // 8文字以上。ここもGitHub/Xserverのパスワードとは別物にする。
    'pairing_code' => 'ここへ8文字以上の合言葉',

    // ── 保存場所 ──────────────────────────────────────────────
    // 端末トークン・投稿状況・記録の置き場所。
    // public_html の外にすること（Webから読まれないため）。
    'storage_dir' => __DIR__ . '/storage',

    // ── 制限 ─────────────────────────────────────────────────
    // 1枚あたりの画像サイズ（バイト）
    'max_image_bytes' => 8 * 1024 * 1024,
    // 1回の投稿で受け取る画像の枚数
    'max_images' => 12,
    // 投稿全体のサイズ（バイト）
    'max_request_bytes' => 40 * 1024 * 1024,
    // 1端末あたり、この秒数の間に投稿できる回数
    'rate_window_seconds' => 3600,
    'rate_max_publishes' => 10,
    // ペアリングの試行回数（同じIPから）
    'rate_max_pairings' => 5,

    // 公開されるサイトのURL。掲載後のリンクを組み立てるのに使う。
    'site_base_url' => 'https://relagarden.jp',
];
