<?php

declare(strict_types=1);

/**
 * 施工事例の投稿を受ける入口。
 *
 * Xserverでは public_html/api/ へ置く。
 * 秘密情報はこのファイルにも、この下のどこにも書かない。
 * public_html の外にある private/config.php から読む。
 */

$sourceDir = getenv('RELAGARDEN_API_SOURCE') ?: '';
if ($sourceDir === '') {
    // リポジトリ内では api/src、本番では public_html の外の api-src を使う。
    $localSource = dirname(__DIR__) . '/src';
    $sourceDir = is_dir($localSource)
        ? $localSource
        : dirname(__DIR__, 2) . '/api-src';
}
$sourceDir = rtrim($sourceDir, '/');

require $sourceDir . '/ApiError.php';
require $sourceDir . '/Config.php';
require $sourceDir . '/Storage.php';
require $sourceDir . '/Auth.php';
require $sourceDir . '/RateLimiter.php';
require $sourceDir . '/Validator.php';
require $sourceDir . '/CaseMarkdown.php';
require $sourceDir . '/GitHubClient.php';
require $sourceDir . '/GitHubApiClient.php';
require $sourceDir . '/FakeGitHubClient.php';
require $sourceDir . '/PublishService.php';
require $sourceDir . '/Router.php';

use Relagarden\Api\Config;
use Relagarden\Api\ConfigMissing;
use Relagarden\Api\GitHubApiClient;
use Relagarden\Api\Router;
use Relagarden\Api\Storage;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// ブラウザーからは使わないため、外部サイトからの利用を許さない。
header('Access-Control-Allow-Origin: null');

// 設定ファイルの場所。public_html の外を指す。
// 置き場所を変える場合はここだけ直す。
$configPath = getenv('RELAGARDEN_API_CONFIG')
    ?: dirname(__DIR__, 3) . '/private/config.php';

try {
    $config = Config::load($configPath);
} catch (ConfigMissing $e) {
    // 何が足りないかは記録にだけ残し、外へは出さない。
    error_log('[relagarden-api] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(
        ['ok' => false, 'message' => 'ただいま準備中です'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$storage = new Storage($config->str('storage_dir'));
$github = new GitHubApiClient(
    $config->str('github_token'),
    $config->str('github_owner'),
    $config->str('github_repo'),
    $config->str('github_branch'),
    $storage,
);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// public_html/api/ の下に置く前提で、先頭の /api を落とす
$path = preg_replace('#^/api#', '', $path) ?? $path;

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = $value;
    }
}

$router = new Router($config, $storage, $github);
[$status, $payload] = $router->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $path,
    (string) file_get_contents('php://input'),
    $headers,
    (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
);

http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
