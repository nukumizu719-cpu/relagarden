<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINE受信だけの受け口。
 *
 * ここには施工事例の掲載（/publish /status /unpublish）は無い。
 * 掲載はiPhoneからGitHubへ直接行う方式のままで、こちらは触らない。
 *
 * | メソッド | 入口              | 用途                                   |
 * | POST     | /api/line/webhook | LINEからの配信を受ける（署名を確認）    |
 * | GET      | /api/line/inbox   | まだ取り込んでいない問い合わせを渡す    |
 * | POST     | /api/line/sync    | 取り込めたものへ受け取り済みの印を付ける |
 */
final class LineRouter
{
    public function __construct(
        private readonly LineConfig $config,
        private readonly LineStore $store,
        private readonly LineProfile $profile,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(
        string $method,
        string $path,
        string $rawBody,
        array $headers,
        string $clientIp,
        array $query = [],
    ): array {
        try {
            $route = '/' . trim($path, '/');

            if ($route === '/webhook') {
                $this->requireMethod($method, 'POST');
                $service = new LineWebhookService($this->config, $this->store, $this->profile);
                return [200, $service->receive($rawBody, $headers)];
            }

            if ($route === '/inbox') {
                $this->requireMethod($method, 'GET');
                $this->requireInboxToken($headers, $clientIp);
                $limit = isset($query['limit']) ? (int) $query['limit'] : 0;
                $service = new LineInboxService($this->config, $this->store);
                return [200, ['ok' => true] + $service->items($limit)];
            }

            if ($route === '/sync') {
                $this->requireMethod($method, 'POST');
                $this->requireInboxToken($headers, $clientIp);
                $body = $this->json($rawBody);
                $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
                $service = new LineInboxService($this->config, $this->store);
                return [200, ['ok' => true] + $service->ack(array_values($ids))];
            }

            return [404, ['ok' => false, 'message' => '入口が見つかりません']];
        } catch (LineError $e) {
            if ($e->detailForLog !== null) {
                $this->store->log($e->detailForLog);
            }
            return [$e->status, ['ok' => false, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            // 内部の事情は返さない。記録にだけ残す。
            $this->store->log('unexpected: ' . $e->getMessage());
            return [500, ['ok' => false, 'message' => 'ただいま処理できません。時間をおいてお試しください']];
        }
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if (strtoupper($actual) !== $expected) {
            throw new LineError(405, '受け付けられない要求です');
        }
    }

    /**
     * 受信箱の合言葉を確かめる。
     *
     * この合言葉はiPhoneのKeychainにだけ入れる。掲載用のPATとは別物。
     *
     * @param array<string,string> $headers
     */
    private function requireInboxToken(array $headers, string $clientIp): void
    {
        $limiter = new LineRateLimiter($this->store, $this->config->int('rate_window_seconds'));
        $limiter->hit('inbox_' . $clientIp, $this->config->int('rate_max_inbox'));

        $header = trim((string) ($headers['authorization'] ?? ''));
        if (!preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
            throw new LineError(401, 'LINE受信の設定が済んでいません');
        }
        if (!hash_equals($this->config->str('inbox_token'), $m[1])) {
            throw new LineError(401, 'LINE受信の設定が済んでいません', 'inbox token mismatch');
        }
    }

    /** @return array<string,mixed> */
    private function json(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new LineError(400, '内容を読み取れません');
        }
        return $data;
    }
}
