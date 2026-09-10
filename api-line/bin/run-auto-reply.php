<?php

declare(strict_types=1);

/**
 * XserverのCronからだけ呼ぶ。公開URLは用意しない。
 * 5分ごとに実行して、期限が来た予約だけを安全に送る。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
$sourceDir = getenv('RELAGARDEN_LINE_SOURCE') ?: '';
if ($sourceDir === '') {
    $sourceDir = is_dir($root . '/src') ? $root . '/src' : $root . '/api-line-src';
}
$sourceDir = rtrim($sourceDir, '/');

require $sourceDir . '/LineError.php';
require $sourceDir . '/LineConfig.php';
require $sourceDir . '/LineStore.php';
require $sourceDir . '/LineMessenger.php';
require $sourceDir . '/LineAutoReplyService.php';

use Relagarden\Line\HttpLineMessenger;
use Relagarden\Line\LineAutoReplyService;
use Relagarden\Line\LineConfig;
use Relagarden\Line\LineStore;

$configPath = getenv('RELAGARDEN_LINE_CONFIG') ?: $root . '/private/line-config.php';

try {
    $config = LineConfig::load($configPath);
    if (!$config->bool('auto_reply_enabled')) {
        exit(0);
    }
    $store = new LineStore($config->str('storage_dir'));
    $messenger = new HttpLineMessenger(
        $config->str('channel_access_token'),
        $config->int('auto_reply_timeout'),
    );
    (new LineAutoReplyService($config, $store, $messenger))->runDue();
    exit(0);
} catch (Throwable $e) {
    // 値・本文・お客様情報は出さず、監視から分かる終了状態だけ返す。
    exit(1);
}
