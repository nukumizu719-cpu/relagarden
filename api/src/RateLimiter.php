<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 回数の制限。
 *
 * 合言葉の総当たりと、投稿の連打を防ぐ。
 * 時間の窓の中で、決めた回数を超えたら断る。
 */
final class RateLimiter
{
    private Storage $storage;
    private int $windowSeconds;

    public function __construct(Storage $storage, int $windowSeconds)
    {
        $this->storage = $storage;
        $this->windowSeconds = max(1, $windowSeconds);
    }

    /**
     * 1回分を数える。上限を超えていたら例外。
     *
     * @throws ApiError
     */
    public function hit(string $key, int $max, string $message): void
    {
        $safe = Storage::safeKey($key);
        $now = time();
        $record = $this->storage->get('rate', $safe) ?? [];

        /** @var list<int> $times */
        $times = [];
        foreach ((array) ($record['times'] ?? []) as $t) {
            if (is_int($t) && $t > $now - $this->windowSeconds) {
                $times[] = $t;
            }
        }

        if (count($times) >= $max) {
            throw new ApiError(429, $message);
        }

        $times[] = $now;
        $this->storage->put('rate', $safe, ['times' => $times]);
    }
}
