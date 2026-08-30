<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 本物のGitHubへつなぐ。
 *
 * トークンはここへ書かない。Config から受け取る。
 * 応答の中身をそのまま利用者へ返さない（内部の事情が漏れるため）。
 */
final class GitHubApiClient implements GitHubClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $branch,
        private readonly Storage $storage,
    ) {
    }

    public function fileExists(string $path): bool
    {
        [$status] = $this->request('GET', $this->contentsUrl($path), null);
        return $status === 200;
    }

    public function createFile(string $path, string $contents, string $message): bool
    {
        [$status, $body] = $this->request('PUT', $this->contentsUrl($path), [
            'message' => $message,
            'content' => base64_encode($contents),
            'branch' => $this->branch,
        ]);

        if ($status === 201) {
            return true;
        }
        // 422 はすでに同じ名前がある場合。上書きはしない。
        if ($status === 422) {
            return false;
        }
        $this->storage->log(sprintf('github error status=%d path=%s', $status, $path));
        unset($body);
        throw new ApiError(502, 'ホームページへの反映に失敗しました');
    }

    public function deleteFile(string $path, string $message): bool
    {
        [$readStatus, $readBody] = $this->request('GET', $this->contentsUrl($path), null);
        if ($readStatus === 404) {
            return true;
        }
        $metadata = json_decode($readBody, true);
        $sha = is_array($metadata) && is_string($metadata['sha'] ?? null)
            ? $metadata['sha']
            : '';
        if ($readStatus !== 200 || $sha === '') {
            $this->storage->log(sprintf('github delete lookup failed status=%d path=%s', $readStatus, $path));
            throw new ApiError(502, 'ホームページから削除できませんでした');
        }

        [$status] = $this->request('DELETE', $this->contentsUrl($path), [
            'message' => $message,
            'sha' => $sha,
            'branch' => $this->branch,
        ]);
        if ($status === 200 || $status === 404) {
            return true;
        }
        $this->storage->log(sprintf('github delete failed status=%d path=%s', $status, $path));
        throw new ApiError(502, 'ホームページから削除できませんでした');
    }

    private function contentsUrl(string $path): string
    {
        return sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s',
            rawurlencode($this->owner),
            rawurlencode($this->repo),
            implode('/', array_map('rawurlencode', explode('/', $path)))
        );
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{0:int,1:string}
     */
    private function request(string $method, string $url, ?array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiError(502, 'ホームページへの反映に失敗しました');
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: relagarden-case-publisher',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_POSTFIELDS] = $json === false ? '{}' : $json;
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->storage->log('github connection failed: ' . $error);
            throw new ApiError(502, 'ホームページへの反映に失敗しました');
        }
        return [$status, is_string($body) ? $body : ''];
    }
}
