<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 受け取った値の検証。
 *
 * アプリから来た値でも信用しない。
 * ここを通ったものだけがGitHubへ送られる。
 */
final class Validator
{
    /** 記事のファイル名に使える形 */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** 受け付ける画像 */
    private const ALLOWED_MIME = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
    ];

    public static function slug(mixed $value): string
    {
        $slug = is_string($value) ? trim($value) : '';
        if ($slug === '' || strlen($slug) > 80) {
            throw new ApiError(400, '記事の名前が正しくありません');
        }
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new ApiError(400, '記事の名前は半角小文字・数字・ハイフンだけにしてください');
        }
        return $slug;
    }

    public static function requiredText(mixed $value, string $label, int $max = 200): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            throw new ApiError(400, sprintf('%sが入っていません', $label));
        }
        return self::cleanText($text, $label, $max);
    }

    public static function optionalText(mixed $value, string $label, int $max = 200): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return '';
        }
        return self::cleanText($text, $label, $max);
    }

    /**
     * 文字を整える。
     *
     * - 制御文字を落とす（改行とタブは残す）
     * - 長さを制限する
     * - 正しい文字コードか確かめる
     */
    private static function cleanText(string $text, string $label, int $max): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new ApiError(400, sprintf('%sの文字が読み取れません', $label));
        }
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        if (mb_strlen($cleaned) > $max) {
            throw new ApiError(400, sprintf('%sが長すぎます（%d文字まで）', $label, $max));
        }
        return $cleaned;
    }

    /**
     * 場所。番地が混ざっていても市区町村までにする。
     *
     * アプリ側でも同じことをしているが、ここでも必ず行う。
     * 「送る側が正しくしてくれる」前提にしない。
     */
    public static function area(mixed $value): string
    {
        $raw = self::requiredText($value, '場所', 60);
        return self::cityOf($raw);
    }

    /** 「愛知県岡崎市中町1-2-3」→「岡崎市」 */
    public static function cityOf(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (preg_match('/(?:.+?[都道府県])?(.+?[市区町村])/u', $address, $m) === 1) {
            $city = trim($m[1]);
            if ($city !== '') {
                return $city;
            }
        }
        // 市区町村が見つからないときも、数字と番地の記号は必ず落とす。
        $withoutNumbers = preg_replace(
            '/[0-9０-９]+[\-−ー－ノの丁目番地号\s]*/u',
            '',
            $address
        ) ?? '';
        $withoutNumbers = trim($withoutNumbers);
        return $withoutNumbers === '' ? $address : $withoutNumbers;
    }

    public static function date(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new ApiError(400, '日付の形式が正しくありません');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $text));
        if (!checkdate($m, $d, $y)) {
            throw new ApiError(400, '日付が正しくありません');
        }
        return $text;
    }

    /** @return list<string> */
    public static function tags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $tags = [];
        foreach ($value as $tag) {
            $clean = self::optionalText($tag, 'タグ', 30);
            if ($clean !== '' && !in_array($clean, $tags, true)) {
                $tags[] = $clean;
            }
            if (count($tags) >= 10) {
                break;
            }
        }
        return $tags;
    }

    public static function consent(mixed $value): void
    {
        if ($value !== true) {
            throw new ApiError(400, 'お客様の掲載許可が確認できていません');
        }
    }

    /**
     * 画像1枚を確かめる。
     *
     * 拡張子や送られてきた種類の申告は信じない。
     * 中身を読んで、本当に画像かどうかを見る。
     *
     * @return array{bytes:string,extension:string}
     */
    public static function image(mixed $base64, int $maxBytes, string $label): array
    {
        if (!is_string($base64) || $base64 === '') {
            throw new ApiError(400, sprintf('%sが読み取れません', $label));
        }
        // 先頭に data:image/...;base64, が付いていても受け取れるようにする
        if (str_contains($base64, ',') && str_starts_with($base64, 'data:')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            throw new ApiError(400, sprintf('%sが読み取れません', $label));
        }
        if (strlen($bytes) > $maxBytes) {
            throw new ApiError(
                413,
                sprintf('%sが大きすぎます（%dMBまで）', $label, (int) ($maxBytes / 1024 / 1024))
            );
        }

        // 中身から種類を判定する
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (!is_string($mime) || !isset(self::ALLOWED_MIME[$mime])) {
            throw new ApiError(400, sprintf('%sは写真として読み取れません', $label));
        }

        // 画像として本当に開けるかも見る（種類だけ偽装した細工を弾く）
        $info = @getimagesizefromstring($bytes);
        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
            throw new ApiError(400, sprintf('%sは写真として読み取れません', $label));
        }

        return ['bytes' => $bytes, 'extension' => self::ALLOWED_MIME[$mime]];
    }

    /**
     * リポジトリへ置くファイル名を組み立てる。
     *
     * 送られてきた名前は一切使わない。こちらで作る。
     * これでパストラバーサルも文字化けも起きない。
     */
    public static function assetName(string $slug, string $role, int $index, string $extension): string
    {
        $safeSlug = self::slug($slug);
        $safeRole = $role === 'after' ? 'after' : 'before';
        $safeIndex = max(1, min(99, $index));
        $safeExt = in_array($extension, self::ALLOWED_MIME, true) ? $extension : '.jpg';
        return sprintf('%s-%s-%02d%s', $safeSlug, $safeRole, $safeIndex, $safeExt);
    }
}
