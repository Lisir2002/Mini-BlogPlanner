<?php

declare(strict_types=1);

namespace Core\Content;

use RuntimeException;

final class PostDraftConflictException extends RuntimeException
{
    /** @param array<string, mixed> $current */
    public function __construct(public readonly array $current)
    {
        parent::__construct('工作稿已在另一个窗口更新。');
    }
}
