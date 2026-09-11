<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 本物のInstagramへつなぐ。
 *
 * Instagram Login方式なので、行き先は graph.instagram.com。
 * APIのバージョンは**ここに書かない。** 設定から受け取る
 * （推測で固定すると、Metaが上げたときに黙って壊れるため）。
 *
 * **アクセストークンはURLへ入れない。** Authorizationヘッダーで送る。
 * クエリ文字列はサーバーの記録に残ることがあるため。
 */
final class CurlInstagramClient implements InstagramClient
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $igUserId,
        private readonly string $apiVersion,
        private readonly Storage $storage,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    private function base(): string
    {
        return 'https://graph.instagram.com/' . $this->apiVersion;
    }

    public function createMediaContainer(string $imageUrl, string $caption): string
    {
        $response = $this->send(
            'POST',
            $this->base() . '/' . $this->igUserId . '/media',
            ['image_url' => $imageUrl, 'caption' => $caption]
        );
        $id = is_string($response['id'] ?? null) ? $response['id'] : '';
        if ($id === '') {
            $this->storage->log('instagram: container response had no id');
            throw new ApiError(502, 'Instagramで下書きを作れませんでした');
        }
        return $id;
    }

    public function containerStatus(string $containerId): string
    {
        $response = $this->send(
            'GET',
            $this->base() . '/' . rawurlencode($containerId) . '?fields=status_code'
        );
        $status = $response['status_code'] ?? '';
        return is_string($status) ? $status : '';
    }

    public function publishMedia(string $containerId): string
    {
        $response = $this->send(
            'POST',
            $this->base() . '/' . $this->igUserId . '/media_publish',
            ['creation_id' => $containerId]
        );
        $id = is_string($response['id'] ?? null) ? $response['id'] : '';
        if ($id === '') {
            // 公開されたのに読み取れなかった可能性がある。成功にも失敗にもしない。
            throw new InstagramUnknownResult('公開の応答を読み取れませんでした');
        }
        return $id;
    }

    /**
     * 公開した投稿のURL。
     *
     * ここで失敗しても投稿そのものは成功している。
     * 取れなければ空文字を返すだけにして、成功を失敗に変えない。
     */
    public function mediaPermalink(string $mediaId): string
    {
        try {
            $response = $this->send(
                'GET',
                $this->base() . '/' . rawurlencode($mediaId) . '?fields=permalink'
            );
            $url = $response['permalink'] ?? '';
            return is_string($url) ? $url : '';
        } catch (\Throwable $e) {
            $this->storage->log('instagram: permalink not available');
            return '';
        }
    }

    /**
     * 1回分の通信。
     *
     * @param array<string,string> $form
     * @return array<string,mixed>
     */
    private function send(string $method, string $url, array $form = []): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiError(502, 'Instagramへつなげませんでした');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            // 証明書の確認は絶対に切らない。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $body === '') {
            // 届いたかどうか分からない。決めつけない。
            $this->storage->log('instagram: no response (' . $this->safe($error) . ')');
            throw new InstagramUnknownResult('応答がありませんでした');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->storage->log('instagram: response was not json (status ' . $status . ')');
            throw new InstagramUnknownResult('応答を読み取れませんでした');
        }

        if ($status >= 500) {
            // サーバー側の不調。届いている可能性があるので決めつけない。
            $this->storage->log('instagram: server error status=' . $status);
            throw new InstagramUnknownResult('Instagram側で処理できませんでした');
        }
        if ($status >= 400) {
            // 断られたことは確か。**応答本文はそのままアプリへ返さない。**
            $this->storage->log(
                'instagram: rejected status=' . $status . ' ' . $this->safe((string) $body)
            );
            throw new ApiError(502, 'Instagramに断られました。設定と権限をご確認ください');
        }

        return $decoded;
    }

    /** 記録へ出す前に、長さを切りトークンらしき文字列を伏せる。 */
    private function safe(string $text): string
    {
        return Storage::maskSecrets(mb_substr($text, 0, 300));
    }
}
