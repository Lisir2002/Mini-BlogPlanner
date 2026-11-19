<?php

declare(strict_types=1);

namespace Core\Page;

use Core\Version\Version;

final class PageContext
{
    public function __construct(
        public readonly string $slug,
        public readonly string $root,
        public readonly Version $version,
    ) {}
}
