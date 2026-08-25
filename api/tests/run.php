<?php

declare(strict_types=1);

/**
 * APIのテスト。
 *
 * Composerを使わない。Xserverに何が入っていても動かせるよう、
 * 素のPHPだけで完結させている。
 *
 *   php api/tests/run.php
 */

require __DIR__ . '/../src/ApiError.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/RateLimiter.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/CaseMarkdown.php';
require __DIR__ . '/../src/GitHubClient.php';
require __DIR__ . '/../src/FakeGitHubClient.php';
require __DIR__ . '/../src/PublishService.php';
require __DIR__ . '/../src/Router.php';

use Relagarden\Api\ApiError;
use Relagarden\Api\Auth;
use Relagarden\Api\CaseMarkdown;
use Relagarden\Api\Config;
use Relagarden\Api\FakeGitHubClient;
use Relagarden\Api\PublishService;
use Relagarden\Api\RateLimiter;
use Relagarden\Api\Router;
use Relagarden\Api\Storage;
use Relagarden\Api\Validator;

// ── ごく小さなテストの道具 ────────────────────────────────
$passed = 0;
$failed = 0;
$currentGroup = '';

function group(string $name): void
{
    global $currentGroup;
    $currentGroup = $name;
    echo "\n" . $name . "\n";
}

function test(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        echo "  ✓ " . $name . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ " . $name . "\n";
        echo "      " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : '真であるはずが偽');
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s 期待=%s 実際=%s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertThrows(int $status, callable $body, string $message = ''): void
{
    try {
        $body();
    } catch (ApiError $e) {
        if ($e->status !== $status) {
            throw new RuntimeException(sprintf(
                '%s 期待した状態=%d 実際=%d (%s)',
                $message,
                $status,
                $e->status,
                $e->getMessage()
            ));
        }
        return;
    }
    throw new RuntimeException($message !== '' ? $message : '断られるはずが通った');
}

/** テスト用の作業場所。毎回まっさらにする。 */
function freshStorage(): Storage
{
    $dir = sys_get_temp_dir() . '/relagarden-api-test-' . bin2hex(random_bytes(4));
    return new Storage($dir);
}

function testConfig(): Config
{
    return new Config([
        'github_token' => 'dummy-not-a-real-token',
        'github_owner' => 'nukumizu719-cpu',
        'github_repo' => 'relagarden',
        'pairing_code' => 'test-pairing-code',
        'storage_dir' => sys_get_temp_dir() . '/relagarden-api-test-cfg',
    ] + Config::defaults());
}

/** 本物のJPEGを1枚作る（中身まで見る検証を通すため） */
function makeJpeg(int $w = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 160, 80));
    ob_start();
    imagejpeg($im, null, 80);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

function makePng(): string
{
    $im = imagecreatetruecolor(20, 20);
    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

/** @return array<string,mixed> */
function validPayload(string $slug = 'case-20260825-1430'): array
{
    return [
        'caseId' => 'local-1',
        'slug' => $slug,
        'title' => 'ワンちゃんが走れるお庭へ',
        'area' => '愛知県岡崎市中町1-2-3',
        'cost' => '約15万円',
        'size' => '30',
        'period' => '2日間',
        'body' => "岡崎市のお客様より、雑草のご相談をいただきました。\n人工芝を施工しました。",
        'tags' => ['人工芝', '雑草対策'],
        'date' => '2026-08-25',
        'beforeImages' => [base64_encode(makeJpeg())],
        'afterImages' => [base64_encode(makeJpeg())],
        'consent' => true,
    ];
}

// ══════════════════════════════════════════════════════════
group('入力の検証');

test('記事の名前は決めた形だけ通す', function (): void {
    assertSame('case-20260825-1430', Validator::slug('case-20260825-1430'));
    assertThrows(400, fn() => Validator::slug('大文字ダメ'), '日本語が通った');
    assertThrows(400, fn() => Validator::slug('UPPER'), '大文字が通った');
    assertThrows(400, fn() => Validator::slug(''), '空が通った');
    assertThrows(400, fn() => Validator::slug('a b'), '空白が通った');
});

test('パストラバーサルを弾く', function (): void {
    assertThrows(400, fn() => Validator::slug('../../etc/passwd'));
    assertThrows(400, fn() => Validator::slug('..%2Fetc'));
    assertThrows(400, fn() => Validator::slug('a/../b'));
});

test('番地は必ず落とす', function (): void {
    assertSame('岡崎市', Validator::area('愛知県岡崎市中町1-2-3'));
    assertSame('岡崎市', Validator::area('岡崎市'));
    assertSame('豊田市', Validator::area('愛知県豊田市'));
    $result = Validator::cityOf('どこかの場所 12-3');
    assertTrue(!str_contains($result, '12'), '数字が残った: ' . $result);
});

test('制御文字を落とす', function (): void {
    $text = Validator::requiredText("あ\x00い\x07う", 'タイトル');
    assertSame('あいう', $text);
});

test('長すぎる文字を断る', function (): void {
    assertThrows(400, fn() => Validator::requiredText(str_repeat('あ', 300), 'タイトル', 100));
});

test('日付の形を確かめる', function (): void {
    assertSame('2026-08-25', Validator::date('2026-08-25'));
    assertThrows(400, fn() => Validator::date('2026/08/25'));
    assertThrows(400, fn() => Validator::date('2026-02-30'), 'ありえない日付が通った');
    assertThrows(400, fn() => Validator::date(''));
});

test('掲載許可がないと断る', function (): void {
    assertThrows(400, fn() => Validator::consent(false));
    assertThrows(400, fn() => Validator::consent('true'));
    assertThrows(400, fn() => Validator::consent(null));
    Validator::consent(true);
});

// ══════════════════════════════════════════════════════════
group('画像の検証');

test('本物のJPEGは通る', function (): void {
    $result = Validator::image(base64_encode(makeJpeg()), 1024 * 1024, '写真');
    assertSame('.jpg', $result['extension']);
});

test('PNGも通る', function (): void {
    $result = Validator::image(base64_encode(makePng()), 1024 * 1024, '写真');
    assertSame('.png', $result['extension']);
});

test('種類を偽っても中身で見抜く', function (): void {
    // JPEGのふりをしたPHP
    $fake = base64_encode('<?php system($_GET["c"]); ?>');
    assertThrows(400, fn() => Validator::image($fake, 1024 * 1024, '写真'));
});

test('画像の先頭だけ真似た細工も断る', function (): void {
    // JPEGの魔法の番号だけ付けた中身
    $fake = base64_encode("\xFF\xD8\xFF\xE0" . str_repeat('A', 100));
    assertThrows(400, fn() => Validator::image($fake, 1024 * 1024, '写真'));
});

test('大きすぎる画像を断る', function (): void {
    $big = base64_encode(makeJpeg(800, 800));
    assertThrows(413, fn() => Validator::image($big, 100, '写真'));
});

test('空や壊れた値を断る', function (): void {
    assertThrows(400, fn() => Validator::image('', 1024, '写真'));
    assertThrows(400, fn() => Validator::image('!!!not-base64!!!', 1024, '写真'));
    assertThrows(400, fn() => Validator::image(null, 1024, '写真'));
});

test('置き先の名前はこちらで作る（送られた名前を使わない）', function (): void {
    $name = Validator::assetName('case-20260825-1430', 'before', 1, '.jpg');
    assertSame('case-20260825-1430-before-01.jpg', $name);
    // 危ない役割名を渡しても before/after にしかならない
    $name2 = Validator::assetName('case-20260825-1430', '../../evil', 2, '.png');
    assertSame('case-20260825-1430-before-02.png', $name2);
});

// ══════════════════════════════════════════════════════════
group('連携と認証');

test('正しい合言葉でトークンを発行する', function (): void {
    $auth = new Auth(testConfig(), freshStorage());
    $result = $auth->pair('test-pairing-code', 'よしさんのiPhone');
    assertTrue(strlen($result['token']) === 64, 'トークンの長さが違う');
    assertTrue(strlen($result['deviceId']) === 16, '端末IDの長さが違う');
});

test('合言葉が違えば断る', function (): void {
    $auth = new Auth(testConfig(), freshStorage());
    assertThrows(401, fn() => $auth->pair('wrong-code', 'iPhone'));
});

test('サーバーにはトークンそのものを残さない', function (): void {
    $storage = freshStorage();
    $auth = new Auth(testConfig(), $storage);
    $result = $auth->pair('test-pairing-code', 'iPhone');
    $record = $storage->get('devices', $result['deviceId']);
    assertTrue($record !== null, '端末の記録がない');
    assertTrue(!isset($record['token']), 'トークンが平文で残っている');
    assertTrue(
        !in_array($result['token'], array_values($record ?? []), true),
        'トークンがどこかに残っている'
    );
});

test('発行したトークンで通る', function (): void {
    $storage = freshStorage();
    $auth = new Auth(testConfig(), $storage);
    $r = $auth->pair('test-pairing-code', 'iPhone');
    $deviceId = $auth->requireDevice('Bearer ' . $r['deviceId'] . '.' . $r['token']);
    assertSame($r['deviceId'], $deviceId);
});

test('形の違うトークンを断る', function (): void {
    $auth = new Auth(testConfig(), freshStorage());
    assertThrows(401, fn() => $auth->requireDevice(null));
    assertThrows(401, fn() => $auth->requireDevice(''));
    assertThrows(401, fn() => $auth->requireDevice('Bearer abc'));
    assertThrows(401, fn() => $auth->requireDevice('Basic user:pass'));
});

test('連携を解除すると通らなくなる', function (): void {
    $storage = freshStorage();
    $auth = new Auth(testConfig(), $storage);
    $r = $auth->pair('test-pairing-code', 'iPhone');
    $header = 'Bearer ' . $r['deviceId'] . '.' . $r['token'];
    $auth->requireDevice($header);
    $auth->revoke($r['deviceId']);
    assertThrows(403, fn() => $auth->requireDevice($header));
});

// ══════════════════════════════════════════════════════════
group('回数の制限');

test('上限を超えたら断る', function (): void {
    $limiter = new RateLimiter(freshStorage(), 3600);
    for ($i = 0; $i < 3; $i++) {
        $limiter->hit('k', 3, 'だめ');
    }
    assertThrows(429, fn() => $limiter->hit('k', 3, 'だめ'));
});

test('別の鍵なら別に数える', function (): void {
    $storage = freshStorage();
    $limiter = new RateLimiter($storage, 3600);
    $limiter->hit('a', 1, 'だめ');
    $limiter->hit('b', 1, 'だめ');
    assertThrows(429, fn() => $limiter->hit('a', 1, 'だめ'));
});

// ══════════════════════════════════════════════════════════
group('記事の組み立て');

test('既存の形と同じ項目が並ぶ', function (): void {
    $md = CaseMarkdown::build([
        'slug' => 'case-1',
        'title' => 'テスト',
        'description' => '説明',
        'date' => '2026-08-25',
        'area' => '岡崎市',
        'cost' => '約15万円',
        'period' => '2日間',
        'size' => '30',
        'body' => '本文です。',
        'tags' => ['人工芝'],
        'beforeAsset' => 'case-1-before-01.jpg',
        'afterAsset' => 'case-1-after-01.jpg',
    ]);
    foreach (['title:', 'description:', 'pubDate:', 'image:', 'beforeImage:', 'area:', 'cost:', 'period:', 'tags:'] as $key) {
        assertTrue(str_contains($md, $key), $key . ' がない');
    }
    assertTrue(str_contains($md, '"../../assets/works/case-1-after-01.jpg"'), 'After画像の場所が違う');
    assertTrue(str_contains($md, '"../../assets/works/case-1-before-01.jpg"'), 'Before画像の場所が違う');
    assertTrue(str_contains($md, '広さ：約30㎡'), '広さがない');
});

test('入力がない項目は出さない', function (): void {
    $md = CaseMarkdown::build([
        'slug' => 'case-1', 'title' => 'テスト', 'description' => '説明',
        'date' => '2026-08-25', 'area' => '岡崎市', 'cost' => '', 'period' => '',
        'size' => '', 'body' => '本文', 'tags' => [],
        'beforeAsset' => 'b.jpg', 'afterAsset' => 'a.jpg',
    ]);
    assertTrue(!str_contains($md, 'cost:'), 'costが出た');
    assertTrue(!str_contains($md, 'period:'), 'periodが出た');
    assertTrue(!str_contains($md, '広さ'), '広さが出た');
});

test('引用符を含む題名でも壊れない', function (): void {
    $md = CaseMarkdown::build([
        'slug' => 'case-1', 'title' => 'あ"い\\う', 'description' => '説明',
        'date' => '2026-08-25', 'area' => '岡崎市', 'cost' => '', 'period' => '',
        'size' => '', 'body' => '本文', 'tags' => [],
        'beforeAsset' => 'b.jpg', 'afterAsset' => 'a.jpg',
    ]);
    // frontmatter として読み直せる形になっている
    preg_match('/^title: (.+)$/m', $md, $m);
    assertSame('あ"い\\う', json_decode($m[1], true));
});

// ══════════════════════════════════════════════════════════
group('投稿');

test('正しい内容なら記事と画像が置かれる', function (): void {
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    $result = $service->publish(validPayload(), 'dev1');

    assertSame('published', $result['status']);
    assertTrue(str_contains($result['url'], '/cases/case-20260825-1430/'), 'URLが違う: ' . $result['url']);
    assertTrue(isset($github->files['src/content/cases/case-20260825-1430.md']), '記事が置かれていない');
    assertTrue(
        isset($github->files['src/assets/works/case-20260825-1430-before-01.jpg']),
        'Before画像が置かれていない'
    );
    assertTrue(
        isset($github->files['src/assets/works/case-20260825-1430-after-01.jpg']),
        'After画像が置かれていない'
    );
});

test('番地はGitHubへ送る内容に入らない', function (): void {
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    $service->publish(validPayload(), 'dev1');
    $md = $github->files['src/content/cases/case-20260825-1430.md'];
    assertTrue(str_contains($md, '岡崎市'), '市区町村がない');
    assertTrue(!str_contains($md, '中町'), '番地の町名が残っている');
    assertTrue(!str_contains($md, '1-2-3'), '番地が残っている');
});

test('複数枚の写真を扱える', function (): void {
    $payload = validPayload('case-multi-1');
    $payload['beforeImages'] = [base64_encode(makeJpeg()), base64_encode(makeJpeg())];
    $payload['afterImages'] = [base64_encode(makeJpeg()), base64_encode(makeJpeg()), base64_encode(makeJpeg())];
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    $service->publish($payload, 'dev1');

    assertTrue(isset($github->files['src/assets/works/case-multi-1-before-02.jpg']), 'Before2枚目がない');
    assertTrue(isset($github->files['src/assets/works/case-multi-1-after-03.jpg']), 'After3枚目がない');
});

test('掲載許可がなければ投稿しない', function (): void {
    $payload = validPayload('case-noconsent');
    $payload['consent'] = false;
    $github = new FakeGitHubClient();
    $service = new PublishService(testConfig(), freshStorage(), $github);
    assertThrows(400, fn() => $service->publish($payload, 'dev1'));
    assertSame([], $github->files, '断ったのに置かれた');
});

test('施工前の写真がなければ投稿しない', function (): void {
    $payload = validPayload('case-nobefore');
    $payload['beforeImages'] = [];
    $service = new PublishService(testConfig(), freshStorage(), new FakeGitHubClient());
    assertThrows(400, fn() => $service->publish($payload, 'dev1'));
});

test('施工後の写真がなければ投稿しない', function (): void {
    $payload = validPayload('case-noafter');
    $payload['afterImages'] = [];
    $service = new PublishService(testConfig(), freshStorage(), new FakeGitHubClient());
    assertThrows(400, fn() => $service->publish($payload, 'dev1'));
});

test('同じ記事名は二重に置かない', function (): void {
    $github = new FakeGitHubClient();
    $storage = freshStorage();
    $service = new PublishService(testConfig(), $storage, $github);
    $service->publish(validPayload('case-dup'), 'dev1');
    assertThrows(409, fn() => $service->publish(validPayload('case-dup'), 'dev1'), '二重に置けてしまった');
});

test('同じ事例IDを二度掲載しない', function (): void {
    $storage = freshStorage();
    $service = new PublishService(testConfig(), $storage, new FakeGitHubClient());
    $service->publish(validPayload('case-a'), 'dev1');
    // 別の記事名でも、同じ事例IDなら断る
    assertThrows(409, function () use ($storage): void {
        $s = new PublishService(testConfig(), $storage, new FakeGitHubClient());
        $s->publish(validPayload('case-b'), 'dev1');
    });
});

test('GitHubが失敗したら失敗として記録する', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient(failAfter: 0);
    $service = new PublishService(testConfig(), $storage, $github);
    assertThrows(502, fn() => $service->publish(validPayload('case-fail'), 'dev1'));
    assertSame('failed', $service->status('local-1')['status']);
});

test('状態を後から確かめられる', function (): void {
    $storage = freshStorage();
    $service = new PublishService(testConfig(), $storage, new FakeGitHubClient());
    assertSame('unknown', $service->status('まだない')['status']);
    $service->publish(validPayload('case-status'), 'dev1');
    $status = $service->status('local-1');
    assertSame('published', $status['status']);
    assertSame('case-status', $status['slug']);
});

// ══════════════════════════════════════════════════════════
group('受け口');

test('決めていない入口は404', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('GET', '/nope', '', [], '127.0.0.1');
    assertSame(404, $status);
});

test('想定しないメソッドを断る', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('GET', '/publish', '', [], '127.0.0.1');
    assertSame(405, $status);
    [$status2] = $router->handle('DELETE', '/pairing', '', [], '127.0.0.1');
    assertSame(405, $status2);
});

test('壊れたJSONを断る', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('POST', '/pairing', '{壊れています', [], '127.0.0.1');
    assertSame(400, $status);
    [$status2] = $router->handle('POST', '/pairing', '', [], '127.0.0.1');
    assertSame(400, $status2);
});

test('認証なしの投稿を断る', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [$status] = $router->handle('POST', '/publish', '{}', [], '127.0.0.1');
    assertSame(401, $status);
});

test('連携から投稿まで通しで動く', function (): void {
    $storage = freshStorage();
    $github = new FakeGitHubClient();
    $router = new Router(testConfig(), $storage, $github);

    [$s1, $b1] = $router->handle(
        'POST',
        '/pairing',
        json_encode(['pairingCode' => 'test-pairing-code', 'deviceName' => 'iPhone']),
        [],
        '127.0.0.1'
    );
    assertSame(200, $s1);
    $token = $b1['token'];

    [$s2, $b2] = $router->handle(
        'POST',
        '/publish',
        json_encode(validPayload('case-e2e')),
        ['authorization' => 'Bearer ' . $token],
        '127.0.0.1'
    );
    assertSame(200, $s2, '投稿が通らなかった: ' . ($b2['message'] ?? ''));
    assertSame('published', $b2['status']);
    assertTrue(isset($github->files['src/content/cases/case-e2e.md']), '記事が置かれていない');
});

test('合言葉の総当たりを止める', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    $body = json_encode(['pairingCode' => 'wrong', 'deviceName' => 'x']);
    $lastStatus = 0;
    for ($i = 0; $i < 8; $i++) {
        [$lastStatus] = $router->handle('POST', '/pairing', $body, [], '10.0.0.1');
    }
    assertSame(429, $lastStatus, '何度でも試せてしまう');
});

// ══════════════════════════════════════════════════════════
group('秘密情報');

test('返す内容に秘密が混ざらない', function (): void {
    $router = new Router(testConfig(), freshStorage(), new FakeGitHubClient());
    [, $body] = $router->handle('POST', '/publish', '{}', [], '127.0.0.1');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    foreach (['dummy-not-a-real-token', 'test-pairing-code', 'github_token'] as $secret) {
        assertTrue(!str_contains((string) $json, $secret), $secret . ' が漏れている');
    }
});

test('記録へ出す前にトークンらしき文字を伏せる', function (): void {
    $masked = Storage::maskSecrets('token=ghp_abcdefghijklmnopqrstuvwxyz012345');
    assertTrue(!str_contains($masked, 'ghp_abcdefghij'), '伏せられていない: ' . $masked);
    assertTrue(str_contains($masked, '***'), '印がない');
});

test('設定が足りなければ読み込みを断る', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-bad-config.php';
    file_put_contents($path, '<?php return ["github_token" => "x"];');
    try {
        Config::load($path);
        throw new RuntimeException('足りない設定が通った');
    } catch (\Relagarden\Api\ConfigMissing $e) {
        assertTrue(true);
    } finally {
        @unlink($path);
    }
});

test('合言葉が短すぎると断る', function (): void {
    $path = sys_get_temp_dir() . '/relagarden-short-config.php';
    file_put_contents($path, '<?php return ' . var_export([
        'github_token' => 'x', 'github_owner' => 'o', 'github_repo' => 'r',
        'pairing_code' => 'short',
    ], true) . ';');
    try {
        Config::load($path);
        throw new RuntimeException('短い合言葉が通った');
    } catch (\Relagarden\Api\ConfigMissing $e) {
        assertTrue(true);
    } finally {
        @unlink($path);
    }
});

// ══════════════════════════════════════════════════════════
echo "\n";
echo str_repeat('─', 50) . "\n";
printf("合格 %d 件 / 失敗 %d 件\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
