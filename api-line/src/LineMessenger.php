<?php

declare(strict_types=1);

namespace Relagarden\Line;

/** LINEへ文字を1通送るための小さな境界。テストでは本物の通信へ出ない。 */
interface LineMessenger
{
    public function pushText(string $lineUserId, string $text, string $retryKey): LinePushResult;
}

final class LinePushResult
{
    private function __construct(public readonly string $kind)
    {
    }

    public static function sent(): self
    {
        return new self('sent');
    }

    public static function retryLater(): self
    {
        return new self('retry');
    }

    public static function rejected(): self
    {
        return new self('rejected');
    }
}

/** 自動返信を使わないときの安全な入れ替え。 */
final class NoLineMessenger implements LineMessenger
{
    public function pushText(string $lineUserId, string $text, string $retryKey): LinePushResult
    {
        return LinePushResult::rejected();
    }
}

/** 本番用。アクセストークンやお客様情報をURL・記録へ出さない。 */
final class HttpLineMessenger implements LineMessenger
{
    public function __construct(
        private readonly string $channelAccessToken,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function pushText(string $lineUserId, string $text, string $retryKey): LinePushResult
    {
        if ($this->channelAccessToken === '' || $lineUserId === '' || !function_exists('curl_init')) {
            return LinePushResult::rejected();
        }

        $body = json_encode([
            'to' => $lineUserId,
            'messages' => [['type' => 'text', 'text' => $text]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return LinePushResult::rejected();
        }

        $handle = curl_init('https://api.line.me/v2/bot/message/push');
        if ($handle === false) {
            return LinePushResult::retryLater();
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, min(10, $this->timeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->channelAccessToken,
                'Content-Type: application/json',
                'X-Line-Retry-Key: ' . $retryKey,
            ],
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($handle);

        // 通信結果が不明でも、同じRetry Keyで再試行すれば二重送信を避けられる。
        if ($failed || $status === 0 || $status === 429 || $status >= 500) {
            return LinePushResult::retryLater();
        }
        // 409は、同じRetry Keyの要求がすでに受理されたという意味。
        if ($status === 200 || $status === 409) {
            return LinePushResult::sent();
        }
        return LinePushResult::rejected();
    }
}

/** テスト用。外へ送らず、送るはずだった内容だけを控える。 */
final class FakeLineMessenger implements LineMessenger
{
    /** @var list<array{lineUserId:string,text:string,retryKey:string}> */
    public array $sent = [];

    /** @var list<LinePushResult> */
    public array $results = [];

    public function pushText(string $lineUserId, string $text, string $retryKey): LinePushResult
    {
        $this->sent[] = compact('lineUserId', 'text', 'retryKey');
        return array_shift($this->results) ?? LinePushResult::sent();
    }
}
