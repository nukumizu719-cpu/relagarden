<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * GitHubへの書き込み。
 *
 * 本物と、テスト用の偽物を差し替えられるように、
 * 使う側はこの取り決めだけを見る。
 */
interface GitHubClient
{
    /** 同じ名前の記事がすでにあるか */
    public function fileExists(string $path): bool;

    /**
     * ファイルを1つ作る。すでにあれば false。
     *
     * @param string $contents 生のバイト列（画像でも文章でもよい）
     */
    public function createFile(string $path, string $contents, string $message): bool;
}
