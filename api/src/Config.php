<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 設定の読み込み。
 *
 * 秘密情報はコードに書かない。public_html の外に置いた
 * config.php から読む。ファイルが無ければ、その旨だけを返す
 * （中身や場所を利用者へ見せない）。
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * 設定ファイルを読む。
     *
     * @throws ConfigMissing 設定が見つからない・壊れている場合
     */
    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigMissing('設定ファイルがありません');
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new ConfigMissing('設定ファイルの形式が正しくありません');
        }

        foreach (['github_token', 'github_owner', 'github_repo', 'pairing_code'] as $key) {
            if (!isset($loaded[$key]) || !is_string($loaded[$key]) || $loaded[$key] === '') {
                throw new ConfigMissing(sprintf('設定 %s が未設定です', $key));
            }
        }
        if (strlen((string) $loaded['pairing_code']) < 8) {
            throw new ConfigMissing('pairing_code は8文字以上にしてください');
        }

        return new self($loaded + self::defaults());
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'github_branch' => 'main',
            'storage_dir' => sys_get_temp_dir() . '/relagarden-api',
            'max_image_bytes' => 8 * 1024 * 1024,
            'max_images' => 12,
            'max_request_bytes' => 40 * 1024 * 1024,
            'rate_window_seconds' => 3600,
            'rate_max_publishes' => 10,
            'rate_max_pairings' => 5,
            'site_base_url' => 'https://relagarden.jp',
        ];
    }

    public function str(string $key): string
    {
        $value = $this->values[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->values[$key] ?? 0;
        return is_int($value) ? $value : (int) $value;
    }
}

final class ConfigMissing extends \RuntimeException
{
}
