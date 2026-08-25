<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 施工事例をGitHubへ追加する。
 *
 * ここを通ったあとは、既存のGitHub Actions が
 * ビルドしてXserverへ配置する。この処理はそこには触らない。
 */
final class PublishService
{
    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly GitHubClient $github,
    ) {
    }

    /**
     * @param array<string,mixed> $input アプリから届いた値（未検証）
     * @return array{status:string,slug:string,url:string,caseId:string}
     */
    public function publish(array $input, string $deviceId): array
    {
        // ── 1. 値を確かめる ──────────────────────────────
        $caseId = Validator::optionalText($input['caseId'] ?? '', '事例ID', 64);
        $slug = Validator::slug($input['slug'] ?? '');
        $title = Validator::requiredText($input['title'] ?? '', 'タイトル', 100);
        $area = Validator::area($input['area'] ?? '');
        $date = Validator::date($input['date'] ?? '');
        $cost = Validator::optionalText($input['cost'] ?? '', '予算', 40);
        $period = Validator::optionalText($input['period'] ?? '', '施工期間', 40);
        $size = Validator::optionalText($input['size'] ?? '', '広さ', 20);
        $body = Validator::requiredText($input['body'] ?? '', '本文', 4000);
        $tags = Validator::tags($input['tags'] ?? []);
        Validator::consent($input['consent'] ?? false);

        $before = $this->images($input['beforeImages'] ?? [], '施工前の写真');
        $after = $this->images($input['afterImages'] ?? [], '施工後の写真');
        if ($before === []) {
            throw new ApiError(400, '施工前の写真がありません');
        }
        if ($after === []) {
            throw new ApiError(400, '施工後の写真がありません');
        }
        $total = count($before) + count($after);
        if ($total > $this->config->int('max_images')) {
            throw new ApiError(413, '写真の枚数が多すぎます');
        }

        // ── 2. すでに掲載していないか ────────────────────
        if ($caseId !== '' && $this->storage->exists('status', $caseId)) {
            $prev = $this->storage->get('status', $caseId) ?? [];
            if (($prev['status'] ?? '') === 'published') {
                throw new ApiError(409, 'この施工事例はすでに掲載されています');
            }
        }
        $casePath = 'src/content/cases/' . $slug . '.md';
        if ($this->github->fileExists($casePath)) {
            throw new ApiError(409, 'この施工事例はすでに掲載されています');
        }

        $this->setStatus($caseId, 'processing', $slug, '');

        // ── 3. 画像を先に置く ────────────────────────────
        // 記事より先に置く。記事だけ出来て写真が無い状態を避けるため。
        $beforeNames = [];
        $afterNames = [];
        try {
            foreach ($before as $i => $image) {
                $name = Validator::assetName($slug, 'before', $i + 1, $image['extension']);
                $this->github->createFile(
                    'src/assets/works/' . $name,
                    $image['bytes'],
                    sprintf('feat(cases): %s の施工前写真を追加', $slug)
                );
                $beforeNames[] = $name;
            }
            foreach ($after as $i => $image) {
                $name = Validator::assetName($slug, 'after', $i + 1, $image['extension']);
                $this->github->createFile(
                    'src/assets/works/' . $name,
                    $image['bytes'],
                    sprintf('feat(cases): %s の施工後写真を追加', $slug)
                );
                $afterNames[] = $name;
            }

            // ── 4. 記事を置く ────────────────────────────
            $markdown = CaseMarkdown::build([
                'slug' => $slug,
                'title' => $title,
                'description' => $this->descriptionFrom($body, $area),
                'date' => $date,
                'area' => $area,
                'cost' => $cost,
                'period' => $period,
                'size' => $size,
                'body' => $body,
                'tags' => $tags,
                'beforeAsset' => $beforeNames[0],
                'afterAsset' => $afterNames[0],
            ]);
            $created = $this->github->createFile(
                $casePath,
                $markdown,
                sprintf('feat(cases): %s を追加', $slug)
            );
            if (!$created) {
                throw new ApiError(409, 'この施工事例はすでに掲載されています');
            }
        } catch (ApiError $e) {
            $this->setStatus($caseId, 'failed', $slug, '');
            $this->storage->log(sprintf(
                'publish failed device=%s slug=%s status=%d',
                $deviceId,
                $slug,
                $e->status
            ));
            throw $e;
        }

        $url = rtrim($this->config->str('site_base_url'), '/') . '/cases/' . $slug . '/';
        $this->setStatus($caseId, 'published', $slug, $url);
        $this->storage->log(sprintf(
            'published device=%s slug=%s images=%d',
            $deviceId,
            $slug,
            $total
        ));

        return [
            'status' => 'published',
            'slug' => $slug,
            'url' => $url,
            'caseId' => $caseId,
        ];
    }

    /** @return array{status:string,slug:string,url:string} */
    public function status(string $caseId): array
    {
        $record = $this->storage->get('status', Storage::safeKey($caseId));
        if ($record === null) {
            return ['status' => 'unknown', 'slug' => '', 'url' => ''];
        }
        return [
            'status' => is_string($record['status'] ?? null) ? $record['status'] : 'unknown',
            'slug' => is_string($record['slug'] ?? null) ? $record['slug'] : '',
            'url' => is_string($record['url'] ?? null) ? $record['url'] : '',
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{bytes:string,extension:string}>
     */
    private function images(mixed $raw, string $label): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $max = $this->config->int('max_image_bytes');
        $images = [];
        foreach ($raw as $item) {
            $images[] = Validator::image($item, $max, $label);
        }
        return $images;
    }

    /** 一覧に出る短い説明。本文の1行目を使う。 */
    private function descriptionFrom(string $body, string $area): string
    {
        $first = trim(explode("\n", $body)[0]);
        if ($first !== '') {
            return mb_substr($first, 0, 120);
        }
        return $area . 'の施工事例です。';
    }

    private function setStatus(string $caseId, string $status, string $slug, string $url): void
    {
        if ($caseId === '') {
            return;
        }
        $this->storage->put('status', Storage::safeKey($caseId), [
            'status' => $status,
            'slug' => $slug,
            'url' => $url,
            'updatedAt' => gmdate('c'),
        ]);
    }
}
