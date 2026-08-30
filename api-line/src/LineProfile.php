<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINEの表示名を取りに行く口。
 *
 * 取れなくてもよい。取れなければ空欄のまま受け取り、
 * 谷口さんが後から手で入れる。**返信は絶対に送らない。**
 */
interface LineProfile
{
    public function displayNameOf(string $lineUserId): string;
}

/**
 * 本物のLINEへ問い合わせる。
 *
 * 使うのは「プロフィール取得」だけ。メッセージ送信APIは呼ばないので、
 * 送信数（無料枠）を消費しない。自動応答の設定にも触れない。
 */
final class HttpLineProfile implements LineProfile
{
    public function __construct(
        private readonly string $channelAccessToken,
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function displayNameOf(string $lineUserId): string
    {
        if ($this->channelAccessToken === '' || $lineUserId === '') {
            return '';
        }
        if (!function_exists('curl_init')) {
            return '';
        }
        $handle = curl_init('https://api.line.me/v2/bot/profile/' . rawurlencode($lineUserId));
        if ($handle === false) {
            return '';
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->channelAccessToken],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($status !== 200 || !is_string($body)) {
            // 取れなくても受信そのものは続ける。空欄で残す。
            return '';
        }
        $data = json_decode($body, true);
        $name = is_array($data) && isset($data['displayName']) ? $data['displayName'] : '';
        return is_string($name) ? mb_substr($name, 0, 60) : '';
    }
}

/** 表示名を取りに行かない。設定にトークンが無いときはこれを使う。 */
final class NoLineProfile implements LineProfile
{
    public function displayNameOf(string $lineUserId): string
    {
        return '';
    }
}

/** テスト用。決めた名前をそのまま返す。 */
final class FakeLineProfile implements LineProfile
{
    /** @param array<string,string> $names */
    public function __construct(private readonly array $names = [])
    {
    }

    public function displayNameOf(string $lineUserId): string
    {
        return $this->names[$lineUserId] ?? '';
    }
}
