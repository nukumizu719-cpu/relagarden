<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 受信箱。アプリが取りに来て、取れたものに印を付ける。
 *
 * - `items()` … まだ取っていないものを、届いた順に返す
 * - `ack()`   … アプリが取り込めたものへ「受け取り済み」の印を付ける
 * - `prune()` … 受け取り済みで日数が経ったものだけ片付ける
 *
 * 消すのは受け取り済みのものだけ。取り込めていないお客様の
 * 問い合わせは、日数が経っても残す。
 */
final class LineInboxService
{
    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
    ) {
    }

    /**
     * まだ取り込んでいない問い合わせを、古い順に返す。
     *
     * @return array<string,mixed>
     */
    public function items(int $limit = 0): array
    {
        $max = $limit > 0 ? $limit : $this->config->int('inbox_limit');
        $max = max(1, min($max, 200));

        $items = [];
        $remaining = 0;
        foreach ($this->store->keys('inbox') as $key) {
            $record = $this->store->get('inbox', $key);
            if ($record === null) {
                continue;
            }
            if (($record['takenAt'] ?? '') !== '') {
                continue;
            }
            if (count($items) >= $max) {
                $remaining++;
                continue;
            }
            $items[] = [
                'id' => (string) ($record['id'] ?? $key),
                'eventKey' => (string) ($record['eventKey'] ?? ''),
                'messageId' => (string) ($record['messageId'] ?? ''),
                'lineUserId' => (string) ($record['lineUserId'] ?? ''),
                'lineDisplayName' => (string) ($record['lineDisplayName'] ?? ''),
                'kind' => (string) ($record['kind'] ?? 'text'),
                'text' => (string) ($record['text'] ?? ''),
                'receivedAt' => (string) ($record['receivedAt'] ?? ''),
            ];
        }

        return ['items' => $items, 'remaining' => $remaining];
    }

    /**
     * アプリが取り込めたものへ印を付ける。
     *
     * 同じidを何度送られても結果は変わらない。
     *
     * @param list<mixed> $ids
     * @return array<string,mixed>
     */
    public function ack(array $ids): array
    {
        $marked = 0;
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $record = $this->store->get('inbox', $id);
            if ($record === null) {
                continue;
            }
            if (($record['takenAt'] ?? '') !== '') {
                continue;
            }
            $record['takenAt'] = gmdate('c');
            $this->store->put('inbox', $id, $record);
            $marked++;
        }
        return ['marked' => $marked];
    }

    /** 受け取り済みで、決めた日数を過ぎたものだけ片付ける。 */
    public function prune(): int
    {
        $days = max(1, $this->config->int('keep_days'));
        $limit = time() - ($days * 86400);
        $removed = 0;

        foreach ($this->store->keys('inbox') as $key) {
            $record = $this->store->get('inbox', $key);
            if ($record === null) {
                continue;
            }
            $takenAt = (string) ($record['takenAt'] ?? '');
            if ($takenAt === '') {
                // まだアプリへ渡っていない。残す。
                continue;
            }
            $taken = strtotime($takenAt);
            if ($taken !== false && $taken < $limit) {
                $this->store->delete('inbox', $key);
                $removed++;
            }
        }

        // 二重処理を防ぐ印も、同じ日数だけ残してから片付ける。
        foreach ($this->store->keys('events') as $key) {
            $record = $this->store->get('events', $key);
            $at = is_array($record) ? strtotime((string) ($record['at'] ?? '')) : false;
            if ($at !== false && $at < $limit) {
                $this->store->delete('events', $key);
            }
        }

        return $removed;
    }
}
