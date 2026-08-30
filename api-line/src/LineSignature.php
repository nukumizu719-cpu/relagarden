<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINEから本当に届いたのかを確かめる。
 *
 * LINEは本文全体をチャネルシークレットで署名し、
 * `X-Line-Signature` に入れて送ってくる。
 * ここが通らないものは、内容を一切読まずに捨てる。
 */
final class LineSignature
{
    public static function isValid(string $channelSecret, string $rawBody, ?string $header): bool
    {
        $given = trim((string) $header);
        if ($channelSecret === '' || $given === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));
        // 文字を1つずつ比べる時間差から署名を当てられないようにする。
        return hash_equals($expected, $given);
    }
}
