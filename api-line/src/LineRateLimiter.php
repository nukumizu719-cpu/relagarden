<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 回数の制限。受信箱の合言葉の総当たりを防ぐ。
 *
 * 数えるところは、読んで足して書くまでを一度に行う（[LineStore::update]）。
 * 途中で割り込まれると、同時に叩かれたぶんが数え落とされてしまうため。
 *
 * Webhookの上限は十分に大きく取る。LINEからの正規の配信を落とすと
 * お客様の問い合わせが消えるので、断るのは明らかに異常な量のときだけ。
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
        $window = max(1, $this->windowSeconds);
        $overLimit = false;

        $this->store->update('rate', $safe, function (array $record) use ($now, $window, $max, &$overLimit): array {
            /** @var list<int> $times */
            $times = [];
            foreach ((array) ($record['times'] ?? []) as $t) {
                if (is_int($t) && $t > $now - $window) {
                    $times[] = $t;
                }
            }
            if (count($times) >= $max) {
                $overLimit = true;
                return ['times' => $times];
            }
            $times[] = $now;
            return ['times' => $times];
        });

        if ($overLimit) {
            throw new LineError(429, $message !== '' ? $message : 'しばらく時間をおいてからお試しください');
        }
    }
}
