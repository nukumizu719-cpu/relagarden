<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 施工事例のMarkdownを組み立てる。
 *
 * 出力の形は scripts/add-before-after-case.mjs と同じにする。
 * どちらで作っても同じ記事になるようにするため、
 * 形を変えるときは両方を必ず合わせること。
 * （形が合っているかは api/tests のテストで確かめている）
 */
final class CaseMarkdown
{
    /**
     * @param array{
     *   slug:string, title:string, description:string, date:string, area:string,
     *   cost:string, period:string, size:string, body:string, tags:list<string>,
     *   beforeAsset:string, afterAsset:string
     * } $data
     */
    public static function build(array $data): string
    {
        $lines = ['---'];
        $lines[] = 'title: ' . self::quote($data['title']);
        $lines[] = 'description: ' . self::quote($data['description']);
        $lines[] = 'pubDate: ' . $data['date'];
        $lines[] = 'image: ' . self::quote('../../assets/works/' . $data['afterAsset']);
        $lines[] = 'beforeImage: ' . self::quote('../../assets/works/' . $data['beforeAsset']);
        $lines[] = 'area: ' . self::quote($data['area']);

        if ($data['cost'] !== '') {
            $lines[] = 'cost: ' . self::quote($data['cost']);
        }
        if ($data['period'] !== '') {
            $lines[] = 'period: ' . self::quote($data['period']);
        }

        $tags = array_map([self::class, 'quote'], $data['tags']);
        $lines[] = 'tags: [' . implode(', ', $tags) . ']';
        $lines[] = 'constructionPoints: []';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## 施工内容';
        $lines[] = '';
        $lines[] = $data['body'] !== '' ? $data['body'] : '施工内容を確認して追記してください。';

        if ($data['size'] !== '') {
            $lines[] = '';
            $lines[] = sprintf('広さ：約%s㎡', $data['size']);
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * frontmatter へ入れる文字列を囲む。
     *
     * JSON と同じ囲み方にする（`"` と `\` が打ち消される）。
     * add-before-after-case.mjs も JSON.stringify を使っているので、
     * まったく同じ結果になる。
     */
    public static function quote(string $value): string
    {
        $json = json_encode(trim($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '""' : $json;
    }
}
