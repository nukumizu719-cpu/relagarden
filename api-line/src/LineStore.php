<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINEで届いた内容の置き場所。
 *
 * データベースは使わない。Xserverの契約に左右されず、
 * ファイルだけで完結させるため（掲載API側と同じ考え方だが、
 * 保存場所も中身も完全に別にしてある）。
 *
 * 置き場所は public_html の外を前提にしている。
 * LINEのユーザーIDはここにだけ残り、外へは出さない。
 */
final class LineStore
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        foreach (['', '/events', '/inbox', '/rate', '/logs'] as $sub) {
            $path = $this->dir . $sub;
            if (!is_dir($path)) {
                @mkdir($path, 0700, true);
            }
        }
    }

    /**
     * 鍵に使ってよい文字だけに絞る。
     * `../` などでフォルダーの外へ出られないようにする。
     */
    public static function safeKey(string $raw): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
        return substr($clean, 0, 128);
    }

    /**
     * 保存する。**書けたかどうかを必ず返す。**
     *
     * 書けていないのに「受け取りました」と答えると、LINEは再送してくれず、
     * お客様の問い合わせがどこにも残らないまま消える。
     *
     * @param array<string,mixed> $data
     */
    public function put(string $bucket, string $key, array $data): bool
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null) {
            return false;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // 途中で落ちても壊れた内容が残らないよう、書いてから差し替える。
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    public function get(string $bucket, string $key): ?array
    {
        $path = $this->pathFor($bucket, $key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function exists(string $bucket, string $key): bool
    {
        $path = $this->pathFor($bucket, $key);
        return $path !== null && is_file($path);
    }

    public function delete(string $bucket, string $key): void
    {
        $path = $this->pathFor($bucket, $key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 鍵を古い順に並べて返す。
     *
     * 受信箱の鍵は「受信時刻＋連番」で作るので、名前順が届いた順になる。
     *
     * @return list<string>
     */
    public function keys(string $bucket): array
    {
        $dir = $this->dir . '/' . self::safeKey($bucket);
        if (!is_dir($dir)) {
            return [];
        }
        $keys = [];
        foreach ((array) (scandir($dir) ?: []) as $name) {
            if (!is_string($name) || !str_ends_with($name, '.json')) {
                continue;
            }
            $keys[] = substr($name, 0, -5);
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * 記録を1行足す。
     *
     * LINEのユーザーIDと本文は記録へ書かない。呼び出す側で外すこと。
     * ここでも念のため、トークンらしき文字列とユーザーIDを伏せる。
     */
    public function log(string $message): void
    {
        // 改行やタブを混ぜて、記録の行を偽装されないようにする。
        $flat = str_replace(["\r", "\n", "\t"], ' ', $message);
        $line = sprintf("%s\t%s\n", gmdate('c'), self::maskSecrets(mb_substr($flat, 0, 500)));
        @file_put_contents(
            $this->dir . '/logs/' . gmdate('Y-m') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 記録へ出す前に、秘密とユーザーIDを伏せる。
     */
    public static function maskSecrets(string $text): string
    {
        $patterns = [
            // LINEのユーザーID（U + 32桁の16進）
            '/\bU[0-9a-f]{32}\b/',
            // チャネルアクセストークン・長い16進文字列
            '/\b[A-Za-z0-9+\/]{60,}={0,2}/',
            '/\b[A-Fa-f0-9]{40,}\b/',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '***', $text) ?? $text;
        }
        return $text;
    }

    private function pathFor(string $bucket, string $key): ?string
    {
        $safeBucket = self::safeKey($bucket);
        $safeKey = self::safeKey($key);
        if ($safeBucket === '' || $safeKey === '') {
            return null;
        }
        return $this->dir . '/' . $safeBucket . '/' . $safeKey . '.json';
    }
}
