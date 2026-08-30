<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 受け口。どの処理を呼ぶかを決める。
 *
 * 決めた入口以外は断る。
 * 想定していないメソッドも断る。
 */
final class Router
{
    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly GitHubClient $github,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(string $method, string $path, string $rawBody, array $headers, string $clientIp): array
    {
        $auth = new Auth($this->config, $this->storage);
        $limiter = new RateLimiter($this->storage, $this->config->int('rate_window_seconds'));

        try {
            $route = '/' . trim($path, '/');

            if ($route === '/pairing') {
                $this->requireMethod($method, 'POST');
                $limiter->hit(
                    'pair_' . $clientIp,
                    $this->config->int('rate_max_pairings'),
                    'しばらく時間をおいてからお試しください'
                );
                $body = $this->json($rawBody);
                $result = $auth->pair(
                    is_string($body['pairingCode'] ?? null) ? $body['pairingCode'] : '',
                    is_string($body['deviceName'] ?? null) ? $body['deviceName'] : 'iPhone'
                );
                return [200, [
                    'ok' => true,
                    'token' => $result['deviceId'] . '.' . $result['token'],
                ]];
            }

            if ($route === '/publish') {
                $this->requireMethod($method, 'POST');
                $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
                if (strlen($rawBody) > $this->config->int('max_request_bytes')) {
                    throw new ApiError(413, '写真が大きすぎます');
                }
                $limiter->hit(
                    'pub_' . $deviceId,
                    $this->config->int('rate_max_publishes'),
                    'しばらく時間をおいてからお試しください'
                );
                $service = new PublishService($this->config, $this->storage, $this->github);
                $result = $service->publish($this->json($rawBody), $deviceId);
                return [200, ['ok' => true] + $result];
            }

            if ($route === '/status') {
                $this->requireMethod($method, 'GET');
                $auth->requireDevice($headers['authorization'] ?? null);
                $caseId = $_GET['caseId'] ?? '';
                $service = new PublishService($this->config, $this->storage, $this->github);
                return [200, ['ok' => true] + $service->status(is_string($caseId) ? $caseId : '')];
            }

            if ($route === '/unpublish') {
                $this->requireMethod($method, 'POST');
                $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
                $limiter->hit(
                    'unpub_' . $deviceId,
                    $this->config->int('rate_max_unpublishes'),
                    'しばらく時間をおいてからお試しください'
                );
                $service = new PublishService($this->config, $this->storage, $this->github);
                $result = $service->unpublish($this->json($rawBody), $deviceId);
                return [200, ['ok' => true] + $result];
            }

            if ($route === '/unpair') {
                $this->requireMethod($method, 'POST');
                $deviceId = $auth->requireDevice($headers['authorization'] ?? null);
                $auth->revoke($deviceId);
                return [200, ['ok' => true]];
            }

            return [404, ['ok' => false, 'message' => '入口が見つかりません']];
        } catch (ApiError $e) {
            if ($e->detailForLog !== null) {
                $this->storage->log($e->detailForLog);
            }
            return [$e->status, ['ok' => false, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            // 内部の事情は利用者へ返さない。記録にだけ残す。
            $this->storage->log('unexpected: ' . $e->getMessage());
            return [500, ['ok' => false, 'message' => 'ただいま処理できません。時間をおいてお試しください']];
        }
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
