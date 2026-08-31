<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 届いた要求の見出し（ヘッダー）を読み取る。
 *
 * Xserverのように、PHPをCGIとして動かすサーバーでは
 * `Authorization` がそのままPHPへ渡らないことがある。
 * その場合はサーバー側の設定で別の名前に入れ直されるので、
 * 次の3か所を順に見る。
 *
 *   1. HTTP_AUTHORIZATION              … そのまま渡ってきた場合
 *   2. REDIRECT_HTTP_AUTHORIZATION     … 内部で転送された場合（.htaccessの書き換え）
 *   3. apache_request_headers()        … Apacheが持っている本物の見出し
 *
 * ここを見落とすと、正しい合言葉を入れても401になり、
 * 「設定したのに使えない」状態になる（2026-09-01に本番で発生）。
 */
final class LineHeaders
{
    /**
     * @param array<string,mixed> $server $_SERVER
     * @param array<string,string>|null $apache apache_request_headers() の結果
     * @return array<string,string> 小文字の見出し名 => 値
     */
    public static function from(array $server, ?array $apache = null): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[self::nameOf(substr($key, 5))] = $value;
            }
        }

        // 内部で転送されると REDIRECT_ が付いた名前で渡ってくる。
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'REDIRECT_HTTP_')) {
                $name = self::nameOf(substr($key, 14));
                if (($headers[$name] ?? '') === '') {
                    $headers[$name] = $value;
                }
            }
        }

        // それでも見つからないときは、Apacheが持っているものを使う。
        if (($headers['authorization'] ?? '') === '' && $apache !== null) {
            foreach ($apache as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    continue;
                }
                if (strtolower($key) === 'authorization' && $value !== '') {
                    $headers['authorization'] = $value;
                }
            }
        }

        return $headers;
    }

    private static function nameOf(string $rawKey): string
    {
        return strtolower(str_replace('_', '-', $rawKey));
    }
}
