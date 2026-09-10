<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 初回案内と、30分間手動返信が無いときの受付案内を管理する。
 *
 * 予約は公開領域外へ保存し、送信は必ず同じRetry Keyを使う。
 * Webhookの再送や定期実行の重なりで同じ案内を二重送信しない。
 */
final class LineAutoReplyService
{
    public const initialText = "ご連絡ありがとうございます🌿\nメッセージを受け付けました。\n\n施工中などですぐに返信できない場合があります。\n日程・お電話・お見積もりの内容を確認後、谷口よりご連絡いたします。\n\n恐れ入りますが、そのままお待ちください。\n\nご用件に合わせて、次の言葉をそのまま送ってください。\n・日程確認\n・電話希望\n・見積確認\n・入金連絡";

    public const waitingText = "ご連絡ありがとうございます🌿\nメッセージを受け付けました。少々お待ちください。";

    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
        private readonly LineMessenger $messenger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->bool('auto_reply_enabled');
    }

    /** 新しい文字メッセージを受信した直後に呼ぶ。 */
    public function onIncoming(string $lineUserId, string $text, ?int $now = null): void
    {
        if (!$this->enabled() || $lineUserId === '' || strlen($lineUserId) > 128) {
            return;
        }
        $now ??= time();
        $userKey = LineStore::hashKey($lineUserId);
        $sessionSeconds = max(300, $this->config->int('auto_reply_session_seconds'));
        $delaySeconds = max(60, $this->config->int('auto_reply_delay_seconds'));
        $suppressed = $this->isAcknowledgement($text) || $this->isKeywordReply($text);

        $ok = $this->store->update('auto-reply-users', $userKey, function (array $state) use (
            $lineUserId,
            $now,
            $sessionSeconds,
            $delaySeconds,
            $suppressed,
            $userKey,
        ): array {
            $lastReceivedAt = (int) ($state['lastReceivedAt'] ?? 0);
            $isNewSession = $lastReceivedAt <= 0 || ($now - $lastReceivedAt) >= $sessionSeconds;

            if ($isNewSession) {
                $oldJob = is_string($state['pendingJobKey'] ?? null) ? $state['pendingJobKey'] : '';
                if ($oldJob !== '') {
                    $this->store->delete('auto-reply-jobs', $oldJob);
                }
                $state = [
                    'lineUserId' => $lineUserId,
                    'sessionId' => bin2hex(random_bytes(16)),
                    'sessionStartedAt' => $now,
                    'initialSentAt' => 0,
                    'followupSentAt' => 0,
                    'manualRepliedAt' => 0,
                    'pendingJobKey' => '',
                ];
            }
            $state['lastReceivedAt'] = $now;

            // LINE管理画面側のキーワード応答や短い挨拶と重ねない。
            if ($suppressed) {
                $oldJob = is_string($state['pendingJobKey'] ?? null) ? $state['pendingJobKey'] : '';
                if ($oldJob !== '') {
                    $this->store->delete('auto-reply-jobs', $oldJob);
                }
                $state['pendingJobKey'] = '';
                // 初回がキーワードや挨拶なら、LINE管理画面側の応答を初回案内として扱う。
                if ($isNewSession) {
                    $state['initialSentAt'] = $now;
                }
                return $state;
            }

            // 初回案内が一時的に送れず再試行中なら、短い案内で上書きしない。
            if (!$isNewSession && (int) ($state['initialSentAt'] ?? 0) <= 0) {
                return $state;
            }

            $kind = $isNewSession ? 'initial' : 'waiting';
            if ($kind === 'waiting' && (int) ($state['followupSentAt'] ?? 0) > 0) {
                return $state;
            }
            $sessionId = (string) ($state['sessionId'] ?? '');
            $jobKey = LineStore::hashKey($userKey . '|' . $sessionId . '|' . $kind);
            $dueAt = $kind === 'initial' ? $now : $now + $delaySeconds;
            $existing = $this->store->get('auto-reply-jobs', $jobKey) ?? [];
            $retryKey = is_string($existing['retryKey'] ?? null) && $existing['retryKey'] !== ''
                ? $existing['retryKey']
                : self::uuidV4();
            $job = [
                'jobKey' => $jobKey,
                'userKey' => $userKey,
                'lineUserId' => $lineUserId,
                'sessionId' => $sessionId,
                'kind' => $kind,
                'dueAt' => $dueAt,
                'scheduledAfterAt' => $now,
                'retryKey' => $retryKey,
                'attempts' => (int) ($existing['attempts'] ?? 0),
            ];
            if (!$this->store->put('auto-reply-jobs', $jobKey, $job)) {
                throw new \RuntimeException('E_AUTOREPLY_JOB_WRITE');
            }
            $state['pendingJobKey'] = $jobKey;
            return $state;
        });
        if (!$ok) {
            throw new \RuntimeException('E_AUTOREPLY_STATE_WRITE');
        }
    }

    /** 谷口さんが公式LINEで実際に返信した後に呼ぶ。 */
    public function markReplied(string $lineUserId, ?int $now = null): void
    {
        if (!$this->enabled()) {
            throw new LineError(503, '自動受付の準備がまだ終わっていません');
        }
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '' || strlen($lineUserId) > 128) {
            throw new LineError(400, 'お客様を確認できません');
        }
        $now ??= time();
        $key = LineStore::hashKey($lineUserId);
        $ok = $this->store->update('auto-reply-users', $key, function (array $state) use ($lineUserId, $now): array {
            $jobKey = is_string($state['pendingJobKey'] ?? null) ? $state['pendingJobKey'] : '';
            if ($jobKey !== '') {
                $this->store->delete('auto-reply-jobs', $jobKey);
            }
            $state['lineUserId'] = $lineUserId;
            $state['manualRepliedAt'] = $now;
            $state['pendingJobKey'] = '';
            return $state;
        });
        if (!$ok) {
            throw new LineError(500, '返信済みを記録できません');
        }
    }

    /** 期限が来た予約を処理する。Xserverの定期実行から呼ぶ。 */
    public function runDue(?int $now = null, ?int $limit = null): array
    {
        if (!$this->enabled()) {
            throw new LineError(503, '自動受付の準備がまだ終わっていません');
        }
        $now ??= time();
        $limit ??= max(1, min(100, $this->config->int('auto_reply_max_per_run')));
        $result = ['checked' => 0, 'sent' => 0, 'retrying' => 0, 'cancelled' => 0];

        foreach ($this->store->keys('auto-reply-jobs') as $jobKey) {
            if ($result['checked'] >= $limit) {
                break;
            }
            $job = $this->store->get('auto-reply-jobs', $jobKey);
            if (!is_array($job) || (int) ($job['dueAt'] ?? PHP_INT_MAX) > $now) {
                continue;
            }
            $result['checked']++;
            $userKey = is_string($job['userKey'] ?? null) ? $job['userKey'] : '';
            if ($userKey === '') {
                $this->store->delete('auto-reply-jobs', $jobKey);
                $result['cancelled']++;
                continue;
            }

            $ok = $this->store->update('auto-reply-users', $userKey, function (array $state) use (
                $jobKey,
                $now,
                &$result,
            ): array {
                $job = $this->store->get('auto-reply-jobs', $jobKey);
                if (!is_array($job) || ($state['pendingJobKey'] ?? '') !== $jobKey) {
                    $this->store->delete('auto-reply-jobs', $jobKey);
                    $result['cancelled']++;
                    return $state;
                }
                if ((int) ($job['dueAt'] ?? PHP_INT_MAX) > $now) {
                    return $state;
                }
                if ((int) ($state['manualRepliedAt'] ?? 0) >= (int) ($job['scheduledAfterAt'] ?? 0)) {
                    $this->store->delete('auto-reply-jobs', $jobKey);
                    $state['pendingJobKey'] = '';
                    $result['cancelled']++;
                    return $state;
                }

                $lineUserId = is_string($job['lineUserId'] ?? null) ? $job['lineUserId'] : '';
                $retryKey = is_string($job['retryKey'] ?? null) ? $job['retryKey'] : '';
                $kind = ($job['kind'] ?? '') === 'initial' ? 'initial' : 'waiting';
                $text = $kind === 'initial' ? self::initialText : self::waitingText;
                $send = $this->messenger->pushText($lineUserId, $text, $retryKey);

                if ($send->kind === 'sent') {
                    $state[$kind === 'initial' ? 'initialSentAt' : 'followupSentAt'] = $now;
                    $state['pendingJobKey'] = '';
                    $this->store->delete('auto-reply-jobs', $jobKey);
                    $result['sent']++;
                    return $state;
                }
                if ($send->kind === 'retry') {
                    $attempts = (int) ($job['attempts'] ?? 0) + 1;
                    if ($attempts <= 10) {
                        $job['attempts'] = $attempts;
                        $job['dueAt'] = $now + max(60, $this->config->int('auto_reply_retry_seconds'));
                        $this->store->put('auto-reply-jobs', $jobKey, $job);
                        $result['retrying']++;
                        return $state;
                    }
                }

                $state['pendingJobKey'] = '';
                $this->store->delete('auto-reply-jobs', $jobKey);
                $this->store->log('E_AUTOREPLY_REJECTED', 1);
                $result['cancelled']++;
                return $state;
            });
            if (!$ok) {
                $this->store->log('E_AUTOREPLY_STATE_WRITE', 1);
            }
        }
        return $result;
    }

    private function isAcknowledgement(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/[\s　。、！!？?・🌿]+/u', '', $normalized) ?? '';
        return in_array($normalized, [
            'はい', 'ありがとう', 'ありがとうございます', 'かしこまりました', '承知しました',
            '了解', '了解です', '了解しました', 'わかりました', '分かりました',
            'よろしくお願いします', 'よろしくお願いいたします', '助かりました',
        ], true);
    }

    private function isKeywordReply(string $text): bool
    {
        $normalized = preg_replace('/[\s　。、！!？?]+/u', '', trim($text)) ?? '';
        return in_array($normalized, [
            '日程確認', '電話希望', '見積確認', '入金連絡', '料金', '写真見積', '対応エリア',
        ], true);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
