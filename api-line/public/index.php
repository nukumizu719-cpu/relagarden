<?php

declare(strict_types=1);

/**
 * 公式LINEの受信だけの入口。
 *
 * Xserverでは public_html/api/line/ へ置く。
 * 施工事例の掲載はiPhoneからGitHubへ直接行う方式のままで、
 * この入口は掲載に一切関わらない（/publish /status /unpublish は無い）。
 *
 * 秘密情報はこのファイルにも、この下のどこにも書かない。
 * public_html の外にある private/line-config.php から読む。
 */

$sourceDir = getenv('RELAGARDEN_LINE_SOURCE') ?: '';
if ($sourceDir === '') {
    // リポジトリ内では api-line/src、本番では public_html の外の api-line-src を使う。
    $localSource = dirname(__DIR__) . '/src';
    $sourceDir = is_dir($localSource)
        ? $localSource
        : dirname(__DIR__, 3) . '/api-line-src';
}
$sourceDir = rtrim($sourceDir, '/');

require $sourceDir . '/LineError.php';
require $sourceDir . '/LineConfig.php';
require $sourceDir . '/LineStore.php';
require $sourceDir . '/LineSignature.php';
require $sourceDir . '/LineProfile.php';
require $sourceDir . '/LineRateLimiter.php';
require $sourceDir . '/LineInboxService.php';
require $sourceDir . '/LineWebhookService.php';
require $sourceDir . '/LineRouter.php';

use Relagarden\Line\HttpLineProfile;
use Relagarden\Line\LineConfig;
use Relagarden\Line\LineConfigMissing;
use Relagarden\Line\LineRouter;
use Relagarden\Line\LineStore;
use Relagarden\Line\NoLineProfile;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// ブラウザーからは使わないため、外部サイトからの利用を許さない。
header('Access-Control-Allow-Origin: null');

// 設定ファイルの場所。public_html の外を指す。
// 置き場所を変える場合はここだけ直す。
$configPath = getenv('RELAGARDEN_LINE_CONFIG')
    ?: dirname(__DIR__, 3) . '/private/line-config.php';

try {
    $config = LineConfig::load($configPath);
} catch (LineConfigMissing $e) {
    // 何が足りないかは記録にだけ残し、外へは出さない。
    error_log('[relagarden-line] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'ただいま準備中です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$store = new LineStore($config->str('storage_dir'));
$token = $config->str('channel_access_token');
$profile = $token === ''
    ? new NoLineProfile()
    : new HttpLineProfile($token, $config->int('profile_timeout'));

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// public_html/api/line/ の下に置く前提で、先頭の /api/line を落とす。
$path = preg_replace('#^/api/line#', '', $path) ?? $path;

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = $value;
    }
}

$query = [];
foreach ($_GET as $key => $value) {
    if (is_string($key) && is_string($value)) {
        $query[$key] = $value;
    }
}

$router = new LineRouter($config, $store, $profile);
[$status, $payload] = $router->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $path,
    (string) file_get_contents('php://input'),
    $headers,
    (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    $query,
);

http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
