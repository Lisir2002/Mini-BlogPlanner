<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class UpgradeLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path) {}

    public function acquire(): bool
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open upgrade lock: %s', $this->path));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
