<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 端末の連携と、その後の認証。
 *
 * アプリには何も埋め込まない。初回だけ合言葉（ペアリングコード）を
 * 入れてもらい、この端末専用のトークンを発行する。
 * アプリはそれをiPhoneのKeychainへしまう。
 *
 * サーバー側にはトークンそのものを置かず、ハッシュだけを持つ。
 * 保存ファイルが漏れても、そこから元のトークンは作れない。
 */
final class Auth
{
    private Config $config;
    private Storage $storage;

    public function __construct(Config $config, Storage $storage)
    {
        $this->config = $config;
        $this->storage = $storage;
    }

    /**
     * 合言葉を確かめて、この端末専用のトークンを発行する。
     *
     * @return array{token:string,deviceId:string}
     * @throws ApiError 合言葉が違う場合
     */
    public function pair(string $pairingCode, string $deviceName): array
    {
        // 文字を1つずつ比べる時間差から合言葉を当てられないようにする。
        if (!hash_equals($this->config->str('pairing_code'), $pairingCode)) {
            throw new ApiError(401, 'ペアリングコードが違います');
        }

        $deviceId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));

        $this->storage->put('devices', $deviceId, [
            'tokenHash' => hash('sha256', $token),
            'name' => mb_substr($deviceName, 0, 60),
            'pairedAt' => gmdate('c'),
            'revoked' => false,
        ]);
        $this->storage->log(sprintf('paired device=%s', $deviceId));

        // トークンを返すのはこの1回だけ。サーバーには残らない。
        return ['token' => $token, 'deviceId' => $deviceId];
    }

    /**
     * Authorization ヘッダーを確かめる。
     *
     * @return string 端末ID
     * @throws ApiError
     */
    public function requireDevice(?string $authorizationHeader): string
    {
        $header = trim((string) $authorizationHeader);
        if (!preg_match('/^Bearer\s+([0-9a-f]{16})\.([0-9a-f]{64})$/', $header, $m)) {
            throw new ApiError(401, 'ホームページとの連携が切れています');
        }
        [$_, $deviceId, $token] = $m;

        $record = $this->storage->get('devices', $deviceId);
        if ($record === null) {
            throw new ApiError(401, 'ホームページとの連携が切れています');
        }
        if (($record['revoked'] ?? false) === true) {
            throw new ApiError(403, 'この端末の連携は解除されています');
        }

        $expected = is_string($record['tokenHash'] ?? null) ? $record['tokenHash'] : '';
        if (!hash_equals($expected, hash('sha256', $token))) {
            throw new ApiError(401, 'ホームページとの連携が切れています');
        }
        return $deviceId;
    }

    public function revoke(string $deviceId): void
    {
        $record = $this->storage->get('devices', $deviceId);
        if ($record === null) {
            return;
        }
        $record['revoked'] = true;
        $record['revokedAt'] = gmdate('c');
        $this->storage->put('devices', $deviceId, $record);
        $this->storage->log(sprintf('revoked device=%s', $deviceId));
    }
}
