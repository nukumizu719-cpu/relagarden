<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * Instagramへの投稿。
 *
 * 本物と、テスト用の偽物を差し替えられるように、
 * 使う側はこの取り決めだけを見る（GitHubClientと同じ考え方）。
 *
 * **アクセストークンはこの取り決めに出てこない。**
 * 実装側（CurlInstagramClient）が内側だけで持ち、
 * 呼び出し側・戻り値・記録のどこにも出さない。
 */
interface InstagramClient
{
    /**
     * 入れ物（メディアコンテナ）を1つ作る。**まだ公開されない。**
     *
     * @param string $imageUrl Metaが取りに来る公開HTTPSのURL
     * @param string $caption  投稿本文
     * @return string コンテナID
     */
    public function createMediaContainer(string $imageUrl, string $caption): string;

    /**
     * 入れ物の状態を見る。
     *
     * 返すのは Instagram の生の値（IN_PROGRESS / FINISHED / ERROR / EXPIRED / PUBLISHED）。
     * アプリ向けの言葉へ直すのは [InstagramService] の仕事。
     */
    public function containerStatus(string $containerId): string;

    /**
     * **公開する。ここだけが実際に投稿される。**
     *
     * @return string 投稿ID（media id）
     */
    public function publishMedia(string $containerId): string;

    /** 公開した投稿のURL。取れなければ空文字。 */
    public function mediaPermalink(string $mediaId): string;
}

/**
 * 「成功したか失敗したかが確かめられなかった」ことを表す。
 *
 * 通信の切断・時間切れ・読めない応答がこれにあたる。
 * **これを成功にも失敗にも決めつけない。**
 * 投稿は届いているかもしれないので、状態は「確認中」で止める。
 */
final class InstagramUnknownResult extends \RuntimeException
{
}
