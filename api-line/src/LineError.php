<?php

declare(strict_types=1);

namespace Relagarden\Line;

/**
 * 利用者へそのまま見せてよいエラー。
 *
 * LINEのユーザーID、チャネルの秘密、ファイルの場所は
 * ここへ入れない。詳しい内容は記録の側だけに残す。
 */
final class LineError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $detailForLog = null,
    ) {
        parent::__construct($message);
    }
}
