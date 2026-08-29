<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 回数の制限。受信箱の合言葉の総当たりを防ぐ。
 *
 * **Webhookそのものには制限をかけない。** LINEからの配信を断ると
 * 再送が続き、お客様の問い合わせを取りこぼすため。
 */
final class LineRateLimiter
{
    public function __construct(
        private readonly LineStore $store,
        private readonly int $windowSeconds,
    ) {
    }

    /** @throws LineError 上限を超えたとき */
    public function hit(string $key, int $max, string $message = 'しばらく時間をおいてからお試しください'): void
    {
        $safe = LineStore::safeKey($key);
        $now = time();
        $record = $this->store->get('rate', $safe) ?? [];

        /** @var list<int> $times */
        $times = [];
        foreach ((array) ($record['times'] ?? []) as $t) {
            if (is_int($t) && $t > $now - max(1, $this->windowSeconds)) {
                $times[] = $t;
            }
        }
        if (count($times) >= $max) {
            throw new LineError(429, $message !== '' ? $message : 'しばらく時間をおいてからお試しください');
        }
        $times[] = $now;
        $this->store->put('rate', $safe, ['times' => $times]);
    }
}
