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
use Relagarden\Line\LineConfigMissing;
use Relagarden\Line\LineStorageUnavailable;
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
// 本番と同じ長さ（openssl rand -hex 32 相当の64文字）で確かめる。
const INBOX_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

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

/** 表示名を取りに行くと必ず失敗する版。取得失敗の確認に使う。 */
final class BrokenLineProfile implements \Relagarden\Line\LineProfile
{
    public function displayNameOf(string $lineUserId): string
    {
        throw new RuntimeException('プロフィールを取得できません');
    }
}

/** 保存フォルダーの場所を取り出す。 */
function storageDirOf(LineStore $store): string
{
    $reflection = new ReflectionProperty(LineStore::class, 'dir');
    return (string) $reflection->getValue($store);
}

/** その月の記録を読む。 */
function readLog(LineStore $store): string
{
    $path = storageDirOf($store) . '/logs/' . gmdate('Y-m') . '.log';
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** 決めたフォルダーを書けない状態にする。 */
function makeUnwritable(LineStore $store, string $sub): string
{
    $path = storageDirOf($store) . '/' . $sub;
    chmod($path, 0500);
    return $path;
}

function makeWritable(string $path): void
{
    @chmod($path, 0700);
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

test('写真やスタンプは受け取らない（中身も取りに行かない）', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    foreach (['image', 'sticker', 'video', 'location', 'file'] as $kind) {
        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'webhookEventId' => 'EV-' . $kind,
                'timestamp' => 1756000000000,
                'source' => ['type' => 'user', 'userId' => 'U44444444444444444444444444444444'],
                'message' => ['id' => 'MSG-' . $kind, 'type' => $kind],
            ]],
        ]);
        [$status, $payload] = postWebhook($router, $body);
        assertSame(200, $status, $kind);
        assertSame(0, $payload['stored'], $kind . ' を受信箱へ入れてしまった');
    }
    assertSame(0, count(getInbox($router)[1]['items']));
});

test('文字以外を読み捨てても、印は残って二度処理しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-IMG2',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U44444444444444444444444444444444'],
            'message' => ['id' => 'MSG-IMG2', 'type' => 'image'],
        ]],
    ]);
    postWebhook($router, $body);
    assertTrue(
        $store->exists('events', 'e' . LineStore::hashKey('EV-IMG2')),
        '印が残っていない'
    );
});

test('本文が大きすぎる配信は断る', function (): void {
    $store = freshStore();
    $router = routerWith($store, [], ['max_body_bytes' => 1024]);
    $body = textEvent('EV-BIG', 'MSG-BIG', 'U12121212121212121212121212121212', str_repeat('あ', 2000));
    [$status, $payload] = postWebhook($router, $body);
    assertSame(413, $status);
    assertSame(false, $payload['ok']);
    assertSame(0, count($store->keys('inbox')));
});

test('極端な数のイベントを送りつけられても受け取らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $events = [];
    for ($i = 0; $i < 200; $i++) {
        $events[] = [
            'type' => 'message',
            'webhookEventId' => 'EV-MANY-' . $i,
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U17171717171717171717171717171717'],
            'message' => ['id' => 'MSG-MANY-' . $i, 'type' => 'text', 'text' => '連投'],
        ];
    }
    [$status] = postWebhook($router, json_encode(['events' => $events]));
    assertSame(413, $status);
    assertSame(0, count($store->keys('inbox')));
});

test('署名は正しくても、壊れた内容は断る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    foreach (['これはJSONではありません', '{"events": "配列ではない"}', '{壊れている'] as $body) {
        [$status] = postWebhook($router, $body);
        assertSame(400, $status, $body);
    }
    assertSame(0, count($store->keys('inbox')));
});

test('表示名の取得が失敗しても、本文は必ず受け取る', function (): void {
    $store = freshStore();
    $router = new LineRouter(testConfig(), $store, new BrokenLineProfile());
    $body = textEvent('EV-BROKEN', 'MSG-BROKEN', 'U13131313131313131313131313131313', '見積りをお願いします');

    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status);
    assertSame(1, $payload['stored'], '表示名が取れないと本文まで捨ててしまった');

    $items = getInbox($router)[1]['items'];
    assertSame('見積りをお願いします', $items[0]['text']);
    assertSame('', $items[0]['lineDisplayName']);
});

test('保存できないときは200を返さない（LINEの再送に任せる）', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $inbox = makeUnwritable($store, 'inbox');

    try {
        $body = textEvent('EV-RO', 'MSG-RO', 'U14141414141414141414141414141414', '保存できないはず');
        [$status, $payload] = postWebhook($router, $body);
        assertSame(500, $status, '書けていないのに受け取ったと答えた');
        assertSame(false, $payload['ok']);
        // 印も残っていない＝再送されたときに、もう一度きちんと試せる。
        assertSame(0, count($store->keys('events')), '本文が無いのに処理済みの印が残った');
    } finally {
        makeWritable($inbox);
    }
});

test('異常な量の配信は断る（LINEは再送する）', function (): void {
    $store = freshStore();
    $router = routerWith($store, [], ['rate_max_webhook' => 3]);
    $user = 'U15151515151515151515151515151515';

    for ($i = 0; $i < 3; $i++) {
        [$status] = postWebhook($router, textEvent('EV-R' . $i, 'MSG-R' . $i, $user, '連続' . $i));
        assertSame(200, $status);
    }
    [$status] = postWebhook($router, textEvent('EV-R9', 'MSG-R9', $user, '4通目'));
    assertSame(429, $status);
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

// ── 途中で失敗したとき ──────────────────────────────────
group('書いている途中で失敗したとき');

test('本文は残ったが印を書けなかったとき、再送で印を付け直す（本文は増えない）', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-MARK', 'MSG-MARK', 'U19191919191919191919191919191919', '消えては困る問い合わせ');

    $events = makeUnwritable($store, 'events');
    try {
        [$status] = postWebhook($router, $body);
        assertSame(500, $status, '印を書けていないのに受け取ったと答えた');
    } finally {
        makeWritable($events);
    }

    // 本文は残っている（ここが消えると問い合わせが失われる）。
    assertSame(1, count($store->keys('inbox')), '本文が残っていない');
    assertSame(0, count($store->keys('events')), '書けないはずの印が残っている');

    // LINEが再送してきた。印を付け直し、本文は増やさない。
    [$status2, $payload2] = postWebhook($router, $body);
    assertSame(200, $status2);
    assertSame(0, $payload2['stored'], '本文を二重に保存した');
    assertSame(1, $payload2['repaired'], '印を付け直していない');
    assertSame(1, count($store->keys('inbox')), '本文が増えた');
    assertSame(2, count($store->keys('events')), '印が揃っていない');

    // 3回目以降は何もしない。
    [, $payload3] = postWebhook($router, $body);
    assertSame(0, $payload3['stored']);
    assertSame(0, $payload3['repaired']);
    assertSame(1, count($store->keys('inbox')));
});

test('印の1つ目は書けて2つ目が書けないときも、再送で揃う', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-HALF', 'MSG-HALF', 'U20202020202020202020202020202020', '半分だけ書けた');

    // 2つ目の印（messageId 側）の置き場所を、書き込めない形でふさぐ。
    $blocked = storageDirOf($store) . '/events/m' . LineStore::hashKey('MSG-HALF') . '.json';
    mkdir($blocked, 0700, true);

    [$status] = postWebhook($router, $body);
    assertSame(500, $status);
    assertSame(1, count($store->keys('inbox')), '本文が残っていない');
    assertTrue($store->exists('events', 'e' . LineStore::hashKey('EV-HALF')), '1つ目の印が無い');

    rmdir($blocked);

    [, $payload] = postWebhook($router, $body);
    assertSame(0, $payload['stored'], '本文を二重に保存した');
    assertSame(1, $payload['repaired']);
    assertTrue($store->exists('events', 'm' . LineStore::hashKey('MSG-HALF')), '2つ目の印が無い');
    assertSame(1, count($store->keys('inbox')));
});

test('同じ配信を10回続けて送っても、受信箱は1件のまま', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = textEvent('EV-10', 'MSG-10', 'U21212121212121212121212121212121', '同じ配信');

    for ($i = 0; $i < 10; $i++) {
        [$status] = postWebhook($router, $body);
        assertSame(200, $status);
    }
    assertSame(1, count($store->keys('inbox')));
    assertSame(1, count(getInbox($router)[1]['items']));
});

test('同じお客様からの別のメッセージは、どちらも残る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $user = 'U22222222222222222222222222222299';

    postWebhook($router, textEvent('EV-A1', 'MSG-A1', $user, '1通目', 1756000000000));
    postWebhook($router, textEvent('EV-A2', 'MSG-A2', $user, '2通目', 1756000000000));

    $items = getInbox($router)[1]['items'];
    assertSame(2, count($items), '同じ時刻でも別のメッセージなら2件残る');
});

// ── 番号の扱い ──────────────────────────────────────────
group('番号（ID）の扱い');

test('webhookEventIdだけでも受け取る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'webhookEventId' => 'EV-ONLY',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U23232323232323232323232323232323'],
            'message' => ['type' => 'text', 'text' => '番号は片方だけ'],
        ]],
    ]);
    [, $payload] = postWebhook($router, $body);
    assertSame(1, $payload['stored']);
    // 再送しても増えない。
    postWebhook($router, $body);
    assertSame(1, count($store->keys('inbox')));
});

test('messageIdだけでも受け取る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U24242424242424242424242424242424'],
            'message' => ['id' => 'MSG-ONLY', 'type' => 'text', 'text' => '番号は片方だけ'],
        ]],
    ]);
    [, $payload] = postWebhook($router, $body);
    assertSame(1, $payload['stored']);
    postWebhook($router, $body);
    assertSame(1, count($store->keys('inbox')));
});

test('番号がどちらも無い配信は保存しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $body = json_encode([
        'events' => [[
            'type' => 'message',
            'timestamp' => 1756000000000,
            'source' => ['type' => 'user', 'userId' => 'U25252525252525252525252525252525'],
            'message' => ['type' => 'text', 'text' => '番号が無い'],
        ]],
    ]);
    [$status, $payload] = postWebhook($router, $body);
    assertSame(200, $status, 'LINEへは200を返す（再送させても同じため）');
    assertSame(0, $payload['stored'], '二度と見分けられないものを保存した');
    assertSame(0, count($store->keys('inbox')));
});

test('記号だけが違う番号を、同じものとして扱わない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $user = 'U26262626262626262626262626262626';

    // 記号を落とすと同じ文字列になる番号たち。
    foreach (['EV-1', 'EV/1', 'EV.1', 'EV_1'] as $i => $eventId) {
        [, $payload] = postWebhook(
            $router,
            textEvent($eventId, 'MSG-SYM-' . $i, $user, '記号ちがい ' . $i, 1756000000000 + $i * 1000)
        );
        assertSame(1, $payload['stored'], $eventId . ' が別のものとして扱われていない');
    }
    assertSame(4, count($store->keys('inbox')));
});

test('フォルダーの外へ出ようとする番号でも壊れない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $dir = storageDirOf($store);

    [, $payload] = postWebhook($router, textEvent(
        '../../../../etc/passwd',
        '../../evil',
        'U27272727272727272727272727272727',
        'パストラバーサル'
    ));
    assertSame(1, $payload['stored']);
    // 置き場所の外にファイルが作られていない。
    assertTrue(!file_exists($dir . '/../evil.json'), '外へ書き出している');
    foreach ($store->keys('events') as $key) {
        assertTrue(!str_contains($key, '.'), '鍵に危ない文字が残っている: ' . $key);
    }
});

test('長すぎる番号は受け取らない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $long = str_repeat('E', 200);
    [$status, $payload] = postWebhook($router, textEvent(
        $long,
        'MSG-LONG',
        'U28282828282828282828282828282828',
        '長すぎる番号'
    ));
    assertSame(200, $status);
    assertSame(0, $payload['stored']);
    assertSame(0, count($store->keys('inbox')));
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

test('合言葉が無いと取り込み済みの印を付けられない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    [$noToken] = $router->handle('POST', '/sync', '{"ids":["x"]}', [], '203.0.113.10');
    assertSame(401, $noToken);
    [$wrongToken] = postSync($router, ['x'], 'ちがう合言葉');
    assertSame(401, $wrongToken);
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

test('一度に送れる取り込み済みの数を超えたら断る', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    $ids = [];
    for ($i = 0; $i < 201; $i++) {
        $ids[] = 'id-' . $i;
    }
    [$status] = postSync($router, $ids);
    assertSame(413, $status);
});

test('長すぎる番号や大きすぎる本文の取り込み済みは断る', function (): void {
    $store = freshStore();
    $router = routerWith($store);

    [$longId] = postSync($router, [str_repeat('x', 200)]);
    assertSame(400, $longId);

    $huge = json_encode(['ids' => ['x'], 'padding' => str_repeat('a', 100000)]);
    [$bigBody] = $router->handle(
        'POST',
        '/sync',
        (string) $huge,
        ['authorization' => 'Bearer ' . INBOX_TOKEN],
        '203.0.113.10'
    );
    assertSame(413, $bigBody);

    [$shape] = $router->handle(
        'POST',
        '/sync',
        '{"ids":"配列ではない"}',
        ['authorization' => 'Bearer ' . INBOX_TOKEN],
        '203.0.113.10'
    );
    assertSame(400, $shape);
});

test('取り込み済みの印を書けなかったら、成功と答えない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    postWebhook($router, textEvent('EV-ACK', 'MSG-ACK', 'U29292929292929292929292929292929', '印を書けない'));
    $id = getInbox($router)[1]['items'][0]['id'];

    $inbox = makeUnwritable($store, 'inbox');
    try {
        [$status, $payload] = postSync($router, [$id]);
        assertSame(500, $status, '書けていないのに済んだと答えた');
        assertSame(false, $payload['ok']);
    } finally {
        makeWritable($inbox);
    }

    // まだ取り込み済みになっていない＝アプリが次にもう一度知らせられる。
    assertSame(1, count(getInbox($router)[1]['items']), '未確認のまま残っていない');

    // 書ける状態に戻れば、やり直せる。
    [$retry, $ok] = postSync($router, [$id]);
    assertSame(200, $retry);
    assertSame(1, $ok['marked']);
    assertSame(0, count(getInbox($router)[1]['items']));
});

// ── 設定と置き場所 ──────────────────────────────────────
group('設定と置き場所の守り');

test('合言葉が64文字未満なら起動しない', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'linecfg') . '.php';
    file_put_contents($path, '<?php return ' . var_export([
        'channel_secret' => SECRET,
        'inbox_token' => 'みじかい合言葉',
        'storage_dir' => sys_get_temp_dir() . '/relagarden-line-cfg',
    ], true) . ';');

    try {
        LineConfig::load($path);
        throw new RuntimeException('短い合言葉で起動してしまった');
    } catch (LineConfigMissing $e) {
        assertSame('E_CONFIG_TOKEN', $e->getMessage());
    } finally {
        @unlink($path);
    }
});

test('置き場所が公開領域なら起動しない', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'linecfg') . '.php';
    file_put_contents($path, '<?php return ' . var_export([
        'channel_secret' => SECRET,
        'inbox_token' => INBOX_TOKEN,
        'storage_dir' => '/home/example/relagarden.jp/public_html/api/line/data',
    ], true) . ';');

    try {
        LineConfig::load($path);
        throw new RuntimeException('公開領域を置き場所にして起動してしまった');
    } catch (LineConfigMissing $e) {
        assertSame('E_CONFIG_STORAGE_PUBLIC', $e->getMessage());
    } finally {
        @unlink($path);
    }
});

test('設定ファイルが無いときも、事情を外へ出さない', function (): void {
    try {
        LineConfig::load('/存在しない/line-config.php');
        throw new RuntimeException('設定が無いのに起動してしまった');
    } catch (LineConfigMissing $e) {
        assertSame('E_CONFIG_MISSING', $e->getMessage());
    }
});

test('置き場所が書けないなら起動しない', function (): void {
    $base = sys_get_temp_dir() . '/relagarden-line-ro-' . bin2hex(random_bytes(4));
    mkdir($base, 0500, true);
    try {
        new LineStore($base . '/data');
        throw new RuntimeException('書けない場所で起動してしまった');
    } catch (LineStorageUnavailable $e) {
        assertTrue(str_starts_with($e->getMessage(), 'E_STORAGE'), $e->getMessage());
    } finally {
        @chmod($base, 0700);
        @rmdir($base);
    }
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

// ── 返信・送信を一切しないこと ──────────────────────────
group('返信を送らないこと');

test('LINEへ送信する呼び出しがコードに無い', function (): void {
    $forbidden = [
        '/v2/bot/message/reply',
        '/v2/bot/message/push',
        '/v2/bot/message/multicast',
        '/v2/bot/message/broadcast',
        '/v2/bot/message/narrowcast',
        // 画像や動画の中身を取りに行く入口
        '/content',
    ];
    foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);
        foreach ($forbidden as $needle) {
            assertTrue(
                !str_contains($code, $needle),
                basename($file) . ' に ' . $needle . ' が入っている'
            );
        }
    }
});

test('replyToken を受け取っても保存しない', function (): void {
    $store = freshStore();
    $router = routerWith($store);
    // textEvent() は本物と同じく replyToken を含む。
    postWebhook($router, textEvent('EV-RT', 'MSG-RT', 'U16161616161616161616161616161616', '返信トークン確認'));

    foreach ($store->keys('inbox') as $key) {
        $record = $store->get('inbox', $key) ?? [];
        assertTrue(!array_key_exists('replyToken', $record), 'replyTokenを保存している');
        assertTrue(
            !str_contains(json_encode($record, JSON_UNESCAPED_UNICODE) ?: '', 'dummy-reply-token'),
            'replyTokenが保存内容に混ざっている'
        );
    }
});

// ── 記録に何を書くか ────────────────────────────────────
group('記録');

test('記録には決まったコードと件数しか書かない', function (): void {
    $store = freshStore();
    $router = routerWith($store, ['U18181818181818181818181818181818' => 'にわ好きたろう']);
    $secretText = '雑草がひどいです。電話は0564-00-0000、住所は岡崎市○○町です';

    // ふつうに受け取る／署名を間違える／壊れた内容を送る、を一通り行う。
    postWebhook($router, textEvent('EV-LOG', 'MSG-LOG', 'U18181818181818181818181818181818', $secretText));
    postWebhook($router, textEvent('EV-LOG2', 'MSG-LOG2', 'U18181818181818181818181818181818', $secretText), 'ちがう署名');
    postWebhook($router, '{壊れている');
    getInbox($router, 'ちがう合言葉');

    $log = readLog($store);
    assertTrue($log !== '', '記録が1行も無い');
    foreach ([
        $secretText,
        '0564-00-0000',
        'U18181818181818181818181818181818',
        'にわ好きたろう',
        SECRET,
        INBOX_TOKEN,
        'EV-LOG',
        'MSG-LOG',
        sys_get_temp_dir(),
        '/api-line/',
        '.php',
    ] as $forbidden) {
        assertTrue(
            !str_contains($log, $forbidden),
            '記録に「' . mb_substr($forbidden, 0, 20) . '」が混ざっている'
        );
    }
    // 中身は「日時・コード・件数」だけ。
    foreach (explode("\n", trim($log)) as $line) {
        assertTrue(
            (bool) preg_match('/^[0-9T:+\-]+\t[A-Z0-9_]+\t\d+$/', $line),
            '決まった形になっていない行がある: ' . $line
        );
    }
});

// ── 結果 ────────────────────────────────────────────────
echo "\n";
echo sprintf("成功 %d / 失敗 %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
