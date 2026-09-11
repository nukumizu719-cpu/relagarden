<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * テスト用のInstagram。**実際には1件も送らない。**
 *
 * 本物のトークンが無くても、準備から公開までの流れを確かめられる。
 * 何回呼ばれたかを数えているので、
 * 「準備だけのつもりが公開まで進んでいないか」をテストで証明できる。
 */
final class FakeInstagramClient implements InstagramClient
{
    /** 入れ物を作った回数 */
    public int $containerCalls = 0;

    /** **公開を呼んだ回数。準備のテストではここが0であること。** */
    public int $publishCalls = 0;

    /** 状態を見に行った回数 */
    public int $statusCalls = 0;

    /** @var list<array{imageUrl:string,caption:string}> 作った入れ物の中身 */
    public array $containers = [];

    /** @var list<string> 公開したコンテナID */
    public array $published = [];

    public function __construct(
        /** 状態の問い合わせに返す値 */
        private string $status = 'FINISHED',
        /** 入れ物作りで失敗させる */
        private bool $failContainer = false,
        /** 公開で「結果が分からない」を起こす */
        private bool $unknownOnPublish = false,
        /** 公開で失敗させる */
        private bool $failPublish = false,
    ) {
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function createMediaContainer(string $imageUrl, string $caption): string
    {
        $this->containerCalls++;
        if ($this->failContainer) {
            throw new ApiError(502, 'Instagramへ送れませんでした');
        }
        $this->containers[] = ['imageUrl' => $imageUrl, 'caption' => $caption];
        return 'container-' . $this->containerCalls;
    }

    public function containerStatus(string $containerId): string
    {
        $this->statusCalls++;
        return $this->status;
    }

    public function publishMedia(string $containerId): string
    {
        $this->publishCalls++;
        if ($this->unknownOnPublish) {
            throw new InstagramUnknownResult('応答を読み取れませんでした');
        }
        if ($this->failPublish) {
            throw new ApiError(502, 'Instagramへ公開できませんでした');
        }
        $this->published[] = $containerId;
        return 'media-' . $this->publishCalls;
    }

    public function mediaPermalink(string $mediaId): string
    {
        return 'https://www.instagram.com/p/' . $mediaId . '/';
    }
}
