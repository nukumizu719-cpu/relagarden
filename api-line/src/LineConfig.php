<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * LINE受信だけの設定。
 *
 * 施工事例の掲載（api/ 側）とは別のファイルを読む。
 * 掲載はiPhoneからGitHubへ直接行う方式のままで、こちらは触らない。
 *
 * 秘密情報はコードに書かない。public_html の外に置いた
 * line-config.php から読む。無ければ、その旨だけを返す。
 */
final class LineConfig
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values + self::defaults();
    }

    /**
     * 設定ファイルを読む。
     *
     * @throws LineConfigMissing 設定が見つからない・足りない場合
     */
    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new LineConfigMissing('LINE用の設定ファイルがありません');
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new LineConfigMissing('LINE用の設定ファイルの形式が正しくありません');
        }

        // チャネルシークレットは署名の確認に必ず要る。
        if (!isset($loaded['channel_secret']) || !is_string($loaded['channel_secret']) || strlen($loaded['channel_secret']) < 16) {
            throw new LineConfigMissing('設定 channel_secret が未設定です');
        }
        // 受信箱を読むための合言葉。iPhoneのKeychainへ入れる値。
        if (!isset($loaded['inbox_token']) || !is_string($loaded['inbox_token']) || strlen($loaded['inbox_token']) < 24) {
            throw new LineConfigMissing('設定 inbox_token は24文字以上にしてください');
        }
        // チャネルアクセストークンは任意。無ければ表示名を取りに行かない
        // （空欄のまま受信し、谷口さんが後から手で入れる）。

        return new self($loaded);
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'channel_access_token' => '',
            'storage_dir' => sys_get_temp_dir() . '/relagarden-line',
            // LINEのWebhookは大きくない。念のための上限。
            'max_body_bytes' => 512 * 1024,
            // 受け取り済みの記録を残す日数。過ぎたものだけ片付ける。
            'keep_days' => 30,
            'rate_window_seconds' => 3600,
            'rate_max_inbox' => 240,
            // 表示名を取りに行くときの待ち時間（秒）。
            'profile_timeout' => 5,
            // 1回の受信箱で返す最大件数。
            'inbox_limit' => 50,
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

final class LineConfigMissing extends \RuntimeException
{
}
