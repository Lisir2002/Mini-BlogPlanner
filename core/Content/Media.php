<?php

declare(strict_types=1);

namespace Core\Content;

final class Media
{
    public function __construct(
        public readonly int $id,
        public readonly string $diskName,
        public readonly string $originalName,
        public readonly string $mime,
        public readonly int $size,
        public readonly int $width,
        public readonly int $height,
        public readonly string $createdAt,
    ) {}

    public function url(): string
    {
        return '/media/' . $this->id . '/' . basename($this->diskName);
    }
}
