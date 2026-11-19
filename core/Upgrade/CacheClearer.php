<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class CacheClearer
{
    public function __construct(private readonly string $cacheDirectory) {}

    public function clear(): int
    {
        if (!is_dir($this->cacheDirectory)) {
            return 0;
        }

        $files = glob(rtrim($this->cacheDirectory, '/') . '/*');
        if ($files === false) {
            throw new RuntimeException('Unable to scan cache directory.');
        }

        $count = 0;
        foreach ($files as $file) {
            if (is_dir($file) && !is_link($file)) {
                continue;
            }
            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }
}
