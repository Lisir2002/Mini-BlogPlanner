<?php

declare(strict_types=1);

namespace Core\Content;

final class Comment
{
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly ?int $parentId,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly string $body,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $approvedAt = null,
        public readonly string $postTitle = '',
        public readonly string $postSlug = '',
    ) {}
}
