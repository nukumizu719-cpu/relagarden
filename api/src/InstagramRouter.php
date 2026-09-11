<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * Instagram実験用の入口だけをまとめる。
 *
 * 既存の [Router] は施工事例の掲載を扱う。そちらへ手を入れずに済むよう、
 * `/instagram/...` はここへ渡す。**掲載・LINEの処理は一切含まない。**
 *
 * 認証・回数制限は既存の [Auth] と [RateLimiter] をそのまま使う。
 */
final class InstagramRouter
{
    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly InstagramClient $client,
    ) {
    }

    /** この入口が扱う道か。 */
    public static function handles(string $route): bool
    {
        return $route === '/instagram' || str_starts_with($route, '/instagram/');
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(
        string $method,
        string $route,
        string $rawBody,
        array $headers,
        string $clientIp,
        array $query,
    ): array {
        $auth = new Auth($this->config, $this->storage);
        $limiter = new RateLimiter($this->storage, $this->config->int('rate_window_seconds'));
        $service = new InstagramService($this->config, $this->storage, $this->client);

        $tail = substr($route, strlen('/instagram'));
        $tail = '/' . trim($tail, '/');

        if ($tail === '/prepare') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            if (strlen($rawBody) > $this->config->int('instagram_max_request_bytes')) {
                throw new ApiError(413, '写真が大きすぎます');
            }
            // 端末ごとの制限。
            $limiter->hit(
                'igprep_' . $deviceId,
                $this->config->int('rate_max_instagram_prepares'),
                'しばらく時間をおいてからお試しください'
            );
            // **投稿先アカウントごとの制限。** 端末を替えても上限を超えられない。
            $limiter->hit(
                InstagramService::accountRateKey($this->config) . '_prep',
                $this->config->int('rate_max_instagram_account_prepares'),
                'このアカウントへの準備が続いています。しばらく時間をおいてください'
            );
            return [200, ['ok' => true] + $service->prepare($this->json($rawBody), $deviceId)];
        }

        if ($tail === '/status') {
            $this->requireMethod($method, 'GET');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            $limiter->hit(
                'igstat_' . $deviceId,
                $this->config->int('rate_max_instagram_status'),
                'しばらく時間をおいてからお試しください'
            );
            $draftId = $query['draftId'] ?? '';
            return [200, ['ok' => true] + $service->status($draftId, $deviceId)];
        }

        if ($tail === '/publish') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            // 端末ごとの制限。
            $limiter->hit(
                'igpub_' . $deviceId,
                $this->config->int('rate_max_instagram_publishes'),
                'しばらく時間をおいてからお試しください'
            );
            // **投稿先アカウントごとの制限。** 端末を替えても上限を超えられない。
            $limiter->hit(
                InstagramService::accountRateKey($this->config) . '_pub',
                $this->config->int('rate_max_instagram_account_publishes'),
                'このアカウントへの投稿が続いています。しばらく時間をおいてください'
            );
            return [200, ['ok' => true] + $service->publish($this->json($rawBody), $deviceId)];
        }

        if ($tail === '/discard') {
            $this->requireMethod($method, 'POST');
            $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
            return [200, ['ok' => true] + $service->discard($this->json($rawBody), $deviceId)];
        }

        // 接続の状態だけを見る。下書きは作らない。
        if ($tail === '/account') {
            $this->requireMethod($method, 'GET');
            $auth->requireDevice($headers['authorization'] ?? null);
            $ready = InstagramService::isConfigured($this->config);
            return [200, [
                'ok' => true,
                'configured' => $ready,
                'accountName' => $ready ? $service->accountName() : '',
            ]];
        }

        return [404, ['ok' => false, 'message' => '入口が見つかりません']];
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if (strtoupper($actual) !== $expected) {
            throw new ApiError(405, '受け付けられない要求です');
        }
    }

    /** @return array<string,mixed> */
    private function json(string $raw): array
    {
        if ($raw === '') {
            throw new ApiError(400, '内容が空です');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiError(400, '内容を読み取れません');
        }
        return $data;
    }
}
