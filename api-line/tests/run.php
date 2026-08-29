<?php

declare(strict_types=1);

/**
 * LINE受信APIのテスト。
 *
 * Composerを使わない。Xserverに何が入っていても動かせるよう、
 * 素のPHPだけで完結させている。本物のLINEへはつながらない。
 *
 *   php api-line/tests/run.php
 */

require __DIR__ . '/../src/LineError.php';
require __DIR__ . '/../src/LineConfig.php';
require __DIR__ . '/../src/LineStore.php';
require __DIR__ . '/../src/LineSignature.php';
require __DIR__ . '/../src/LineProfile.php';
require __DIR__ . '/../src/LineRateLimiter.php';
require __DIR__ . '/../src/LineInboxService.php';
require __DIR__ . '/../src/LineWebhookService.php';
require __DIR__ . '/../src/LineRouter.php';

use Relagarden\Line\FakeLineProfile;
use Relagarden\Line\LineConfig;
use Relagarden\Line\LineInboxService;
use Relagarden\Line\LineRouter;
use Relagarden\Line\LineSignature;
use Relagarden\Line\LineStore;

// ── ごく小さなテストの道具 ────────────────────────────────
$passed = 0;
$failed = 0;

function group(string $name): void
{
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

// ── 準備 ────────────────────────────────────────────────
const SECRET = 'test-channel-secret-0123456789';
const INBOX_TOKEN = 'test-inbox-token-0123456789abcdef';

function freshStore(): LineStore
{
    $dir = sys_get_temp_dir() . '/relagarden-line-test-' . bin2hex(random_bytes(6));
    return new LineStore($dir);
}

function testConfig(array $overrides = []): LineConfig
{
    return new LineConfig($overrides + [
        'channel_secret' => SECRET,
        'inbox_token' => INBOX_TOKEN,
        'storage_dir' => sys_get_temp_dir() . '/relagarden-line-test-unused',
    ]);
}

/** 本物のLINEと同じ形の署名を作る。 */
function sign(string $body): string
{
    return base64_encode(hash_hmac('sha256', $body, SECRET, true));
}

/** テキストメッセージ1件分のWebhook本文を作る。 */
function textEvent(
    string $eventId,
    string $messageId,
    string $userId,
    string $text,
    int $timestampMs = 1756000000000,
): string {
    return json_encode([
        'destination' => 'Uffffffffffffffffffffffffffffffff',
        'events' => [[
            'type' => 'message',
            'webhookEventId' => $eventId,
            'timestamp' => $timestampMs,
            'mode' => 'active',
            'source' => ['type' => 'user', 'userId' => $userId],
            'replyToken' => 'dummy-reply-token',
            'message' => ['id' => $messageId, 'type' => 'text', 'text' => $text],
        ]],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Webhookを1回送る。返り値は [status, payload]。
 *
 * @return array{0:int,1:array<string,mixed>}
 */
function postWebhook(LineRouter $router, string $body, ?string $signature = null): array
{
    return $router->handle(
        'POST',
        '/webhook',
        $body,
        ['x-line-signature' => $signature ?? sign($body)],
        '203.0.113.10',
    );
}

function routerWith(LineStore $store, array $names = [], array $configOverrides = []): LineRouter
{
    return new LineRouter(
        testConfig($configOverrides),
        $store,
        new FakeLineProfile($names),
    );
}

/** @return array{0:int,1:array<string,mixed>} */
function getInbox(LineRouter $router, string $token = INBOX_TOKEN): array
{
    return $router->handle('GET', '/inbox', '', ['authorization' => 'Bearer ' . $token], '203.0.113.10');
}

/** @return array{0:int,1:array<string,mixed>} */
function postSync(LineRouter $router, array $ids, string $token = INBOX_TOKEN): array
{
    return $router->handle(
        'POST',
        '/sync',
        json_encode(['ids' => $ids]),
        ['authorization' => 'Bearer ' . $token],
        '203.0.113.10',
    );
}

// ── 署名 ────────────────────────────────────────────────
group('署名の確認');

test('LINEが作る署名と同じものを正しいと判断する', function (): void {
    $body = '{"events":[]}';
    assertTrue(LineSignature::isValid(SECRET, $body, sign($body)));
});

test('本文が1文字でも違えば断る', function (): void {
    $body = '{"events":[]}';
    assertTrue(!LineSignature::isValid(SECRET, $body . ' ', sign($body)));
});

test('署名が無ければ断る', function (): void {
    assertTrue(!LineSignature::isValid(SECRET, '{}', null));
    assertTrue(!LineSignature::isValid(SECRET, '{}', ''));
});

// ── Webhook ─────────────────────────────────────────────
group('Webhookの受信');

test('署名が違う配信は受け取らず、受信箱にも入らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV1', 'MSG1', 'U11111111111111111111111111111111', '人工芝の見積りをお願いします');

    [$status, $payload] = postWebhook($router, $body, 'ちがう署名');
    assertSame(400, $status);
    assertSame(false, $payload['ok']);
    assertSame(0, count($store->keys('inbox')), '受信箱へ入ってしまった');
});

test('正しい配信は受信箱へ入る', function (): void {
    $store = freshStore();
    $router = routerWith($store, ['U11111111111111111111111111111111' => 'にわ好きたろう']);
    $body = textEvent('EV1', 'MSG1', 'U11111111111111111111111111111111', '庭の雑草がひどくて困っています');

    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status);
    assertSame(1, $payload['stored']);

    [$s2, $inbox] = getInbox($router);
    assertSame(200, $s2);
    assertSame(1, count($inbox['items']));
    $item = $inbox['items'][0];
    assertSame('庭の雑草がひどくて困っています', $item['text']);
    assertSame('にわ好きたろう', $item['lineDisplayName']);
    assertSame('U11111111111111111111111111111111', $item['lineUserId']);
    assertSame('text', $item['kind']);
    assertSame('EV1', $item['eventKey']);
    assertSame('MSG1', $item['messageId']);
});

test('同じ webhookEventId は二度処理しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-SAME', 'MSG-A', 'U22222222222222222222222222222222', 'こんにちは');

    [, $first] = postWebhook($router, $body);
    [, $second] = postWebhook($router, $body);
    assertSame(1, $first['stored']);
    assertSame(0, $second['stored'], '同じ配信が二重に入った');
    assertSame(1, count(getInbox($router)[1]['items']));
});

test('webhookEventIdが違っても、同じ messageId なら二度処理しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $user = 'U33333333333333333333333333333333';

    postWebhook($router, textEvent('EV-A', 'MSG-SAME', $user, '見積りをお願いします'));
    [, $second] = postWebhook($router, textEvent('EV-B', 'MSG-SAME', $user, '見積りをお願いします'));

    assertSame(0, $second['stored'], '同じメッセージが二重に入った');
    assertSame(1, count(getInbox($router)[1]['items']));
});

test('LINEの「検証」ボタン（イベントなし）でも200を返す', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status, $payload] = postWebhook($router, '{"destination":"U0","events":[]}');
    assertSame(200, $status);
    assertSame(true, $payload['ok']);
    assertSame(0, count($store->keys('inbox')));
});

test('写真やスタンプは、本文を作らずに種類だけ残す', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-IMG',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U44444444444444444444444444444444'],
            'message' => ['id' => 'MSG-IMG', 'type' => 'image'],
        ]],
    ]);
    postWebhook($router, $body);

    $items = getInbox($router)[1]['items'];
    assertSame(1, count($items));
    assertSame('image', $items[0]['kind']);
    assertSame('', $items[0]['text'], '中身のない本文を作ってしまった');
});

test('グループからのメッセージは受信箱へ入れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-GROUP',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'group', 'groupId' => 'C0000', 'userId' => 'U55555555555555555555555555555555'],
            'message' => ['id' => 'MSG-GROUP', 'type' => 'text', 'text' => 'グループの発言'],
        ]],
    ]);
    [, $payload] = postWebhook($router, $body);
    assertSame(0, $payload['stored']);
});

test('友だち追加だけでは受信箱へ入れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'follow',
            'webhookEventId' => 'EV-FOLLOW',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U66666666666666666666666666666666'],
        ]],
    ]);
    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status);
    assertSame(0, $payload['stored']);
});

test('表示名が取れなくても、問い合わせは受け取る', function (): void {
    $store = freshStore();
    // 名前を1件も知らない＝チャネルアクセストークン未設定と同じ状態
    $router = routerWith($store, []);
    postWebhook($router, textEvent('EV-NONAME', 'MSG-NONAME', 'U77777777777777777777777777777777', '相談したいです'));

    $items = getInbox($router)[1]['items'];
    assertSame(1, count($items));
    assertSame('', $items[0]['lineDisplayName']);
    assertSame('相談したいです', $items[0]['text']);
});

test('GETでWebhookを叩いても受け付けない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = $router->handle('GET', '/webhook', '', [], '203.0.113.10');
    assertSame(405, $status);
});

// ── 受信箱 ──────────────────────────────────────────────
group('受信箱と取り込み');

test('合言葉が無いと受信箱を読めない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = $router->handle('GET', '/inbox', '', [], '203.0.113.10');
    assertSame(401, $status);
});

test('合言葉が違うと受信箱を読めない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status] = getInbox($router, 'ちがう合言葉');
    assertSame(401, $status);
});

test('取り込み済みの印を付けると、次からは渡さない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-1', 'MSG-1', 'U88888888888888888888888888888888', '1件目'));
    postWebhook($router, textEvent('EV-2', 'MSG-2', 'U88888888888888888888888888888888', '2件目', 1756000060000));

    $items = getInbox($router)[1]['items'];
    assertSame(2, count($items));
    assertSame('1件目', $items[0]['text'], '古い順に並んでいない');

    [$status, $acked] = postSync($router, [$items[0]['id']]);
    assertSame(200, $status);
    assertSame(1, $acked['marked']);

    $left = getInbox($router)[1]['items'];
    assertSame(1, count($left));
    assertSame('2件目', $left[0]['text']);
});

test('同じ取り込み済みの印を二度送っても壊れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-9', 'MSG-9', 'U99999999999999999999999999999999', '重ねて送る'));
    $id = getInbox($router)[1]['items'][0]['id'];

    postSync($router, [$id]);
    [, $again] = postSync($router, [$id]);
    assertSame(0, $again['marked']);
    assertSame(0, count(getInbox($router)[1]['items']));
});

test('知らないidを送られても何も起きない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$status, $payload] = postSync($router, ['../../etc/passwd', '', 'いない番号']);
    assertSame(200, $status);
    assertSame(0, $payload['marked']);
});

test('取り込めていない問い合わせは、日数が経っても消さない', function (): void {
    $store = freshStore();
    $config = testConfig(['keep_days' => 1]);
    $old = gmdate('c', time() - (10 * 86400));

    // 取り込み済みで古いもの
    $store->put('inbox', '00000000000001-aaaaaaaaaaaa', [
        'id' => '00000000000001-aaaaaaaaaaaa', 'text' => '古い・取り込み済み', 'takenAt' => $old,
    ]);
    // まだ取り込んでいない古いもの
    $store->put('inbox', '00000000000002-bbbbbbbbbbbb', [
        'id' => '00000000000002-bbbbbbbbbbbb', 'text' => '古い・未取り込み', 'takenAt' => '',
    ]);

    $removed = (new LineInboxService($config, $store))->prune();
    assertSame(1, $removed);
    assertTrue(!$store->exists('inbox', '00000000000001-aaaaaaaaaaaa'), '取り込み済みが残っている');
    assertTrue($store->exists('inbox', '00000000000002-bbbbbbbbbbbb'), '未取り込みを消してしまった');
});

// ── 掲載とは分かれていること ────────────────────────────
group('掲載の入口を持たないこと');

foreach (['/publish', '/status', '/unpublish', '/pairing'] as $route) {
    test('この入口には ' . $route . ' が無い', function () use ($route): void {
        $store = freshStore();
        $router = routerWith($store);
        [$status] = $router->handle('POST', $route, '{}', ['authorization' => 'Bearer ' . INBOX_TOKEN], '203.0.113.10');
        assertSame(404, $status);
    });
}

// ── 記録に秘密を残さないこと ────────────────────────────
group('記録の伏せ字');

test('LINEのユーザーIDは記録へ書かない', function (): void {
    $masked = LineStore::maskSecrets('user=Uabcdef0123456789abcdef0123456789 kind=text');
    assertTrue(!str_contains($masked, 'Uabcdef0123456789abcdef0123456789'), 'ユーザーIDが記録に残る');
});

// ── 結果 ────────────────────────────────────────────────
echo "\n";
echo sprintf("成功 %d / 失敗 %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
