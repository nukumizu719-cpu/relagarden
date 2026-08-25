<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * テスト用のGitHub。実際には何も送らない。
 *
 * 本物のトークンが無くても、投稿の流れ全体を確かめられる。
 */
final class FakeGitHubClient implements GitHubClient
{
    /** @var array<string,string> path => contents */
    public array $files = [];

    /** @var list<string> */
    public array $messages = [];

    public function __construct(
        /** 用意した数だけ失敗させる（失敗の扱いを確かめる用） */
        private int $failAfter = -1,
    ) {
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function createFile(string $path, string $contents, string $message): bool
    {
        $this->maybeFail();
        if (isset($this->files[$path])) {
            return false;
        }
        $this->files[$path] = $contents;
        $this->messages[] = $message;
        return true;
    }

    public function deleteFile(string $path, string $message): bool
    {
        $this->maybeFail();
        unset($this->files[$path]);
        $this->messages[] = $message;
        return true;
    }

    private function maybeFail(): void
    {
        if ($this->failAfter === 0) {
            throw new ApiError(502, 'ホームページへの反映に失敗しました');
        }
        if ($this->failAfter > 0) {
            $this->failAfter--;
        }
    }
}
