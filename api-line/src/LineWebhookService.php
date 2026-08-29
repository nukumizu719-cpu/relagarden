<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 公式LINEに届いたメッセージを受け取って、受信箱へ入れる。
 *
 * **ここでは返信を一切送らない。** 自動応答（応答メッセージ）は
 * LINE側の設定のまま動く。このAPIは受け取って控えるだけ。
 *
 * 同じ webhookEventId ・ messageId は二度処理しない。
 * LINEは同じ配信を再送することがあるため、印を残して弾く。
 */
final class LineWebhookService
{
    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
        private readonly LineProfile $profile,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @throws LineError
     */
    public function receive(string $rawBody, array $headers): array
    {
        if (strlen($rawBody) > $this->config->int('max_body_bytes')) {
            throw new LineError(413, '内容が大きすぎます', 'webhook body too large');
        }
        if (!LineSignature::isValid(
            $this->config->str('channel_secret'),
            $rawBody,
            $headers['x-line-signature'] ?? null
        )) {
            // 中身は読まない。誰から来たかも記録しない。
            throw new LineError(400, '受け付けられない要求です', 'webhook signature mismatch');
        }

        $data = json_decode($rawBody === '' ? '{}' : $rawBody, true);
        if (!is_array($data)) {
            throw new LineError(400, '内容を読み取れません', 'webhook body not json');
        }
        /** @var list<mixed> $events */
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];

        $stored = 0;
        $skipped = 0;
        /** @var array<string,string> $nameCache */
        $nameCache = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                $skipped++;
                continue;
            }
            $result = $this->handleEvent($event, $nameCache);
            if ($result) {
                $stored++;
            } else {
                $skipped++;
            }
        }

        $this->housekeeping();

        // LINEへは必ず 200 を返す。ここで断ると再送が続く。
        return ['ok' => true, 'received' => count($events), 'stored' => $stored, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,string> $nameCache
     */
    private function handleEvent(array $event, array &$nameCache): bool
    {
        $eventId = is_string($event['webhookEventId'] ?? null) ? $event['webhookEventId'] : '';
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $messageId = is_string($message['id'] ?? null) ? $message['id'] : '';

        // 二重処理を防ぐ印。どちらか片方でも見覚えがあれば何もしない。
        $marks = [];
        if ($eventId !== '') {
            $marks[] = 'e' . LineStore::safeKey($eventId);
        }
        if ($messageId !== '') {
            $marks[] = 'm' . LineStore::safeKey($messageId);
        }
        foreach ($marks as $mark) {
            if ($this->store->exists('events', $mark)) {
                return false;
            }
        }

        $isMessage = ($event['type'] ?? '') === 'message';
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $lineUserId = is_string($source['userId'] ?? null) ? $source['userId'] : '';
        $isDirect = ($source['type'] ?? '') === 'user' && $lineUserId !== '';

        // 印だけ先に残す。ここから先で何が起きても、同じ配信を二度扱わない。
        foreach ($marks as $mark) {
            $this->store->put('events', $mark, ['at' => gmdate('c')]);
        }

        // 1対1のメッセージ以外（友だち追加・グループ・スタンプの既読など）は
        // 受信箱へ入れない。お客様1件として扱えないため。
        if (!$isMessage || !$isDirect) {
            return false;
        }

        $kind = is_string($message['type'] ?? null) ? $message['type'] : 'unknown';
        $text = '';
        if ($kind === 'text' && is_string($message['text'] ?? null)) {
            // 本文は書き換えない。長すぎるものだけ切る。
            $text = mb_substr($message['text'], 0, 4000);
        }

        if (!array_key_exists($lineUserId, $nameCache)) {
            $nameCache[$lineUserId] = $this->profile->displayNameOf($lineUserId);
        }

        $timestamp = $event['timestamp'] ?? null;
        $receivedMs = is_int($timestamp) ? $timestamp : (int) (microtime(true) * 1000);
        $key = sprintf('%014d', $receivedMs) . '-' . substr(sha1($eventId . '|' . $messageId . '|' . $lineUserId), 0, 12);

        if ($this->store->exists('inbox', $key)) {
            return false;
        }

        $this->store->put('inbox', $key, [
            'id' => $key,
            'eventKey' => $eventId !== '' ? $eventId : $messageId,
            'messageId' => $messageId,
            'lineUserId' => $lineUserId,
            'lineDisplayName' => $nameCache[$lineUserId],
            'kind' => $kind,
            'text' => $text,
            'receivedAt' => gmdate('c', (int) ($receivedMs / 1000)),
            'takenAt' => '',
        ]);
        // 本文もユーザーIDも記録へは書かない。件数だけ分かればよい。
        $this->store->log('inbox stored kind=' . $kind);
        return true;
    }

    /**
     * 1日に1回だけ、古い受け取り済みの控えを片付ける。
     *
     * 受け取っていないものは日数が経っても消さない。
     */
    private function housekeeping(): void
    {
        $today = gmdate('Y-m-d');
        $last = $this->store->get('rate', 'housekeeping');
        if (is_array($last) && ($last['day'] ?? '') === $today) {
            return;
        }
        $this->store->put('rate', 'housekeeping', ['day' => $today]);
        (new LineInboxService($this->config, $this->store))->prune();
    }
}
