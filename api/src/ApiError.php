<?php

declare(strict_types=1);

namespace Relagarden\Api;

/**
 * 利用者へそのまま見せてよいエラー。
 *
 * 内部の事情（トークン、ファイルの場所、GitHubの応答本文）は
 * ここへ入れない。詳しい内容は記録の側だけに残す。
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $detailForLog = null,
    ) {
        parent::__construct($message);
    }
}
