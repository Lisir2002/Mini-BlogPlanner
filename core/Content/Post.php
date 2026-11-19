<?php

declare(strict_types=1);

namespace Core\Content;

final class Post
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $body,
        public readonly string $publishedAt,
        public readonly int $readingMinutes,
        public readonly array $tags = [],
    ) {}
}
