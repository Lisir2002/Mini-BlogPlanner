<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class UpgradeStateStore
{
    public function __construct(private readonly string $directory) {}

    /** @return array<string, mixed>|null */
    public function load(): ?array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException('Upgrade state is corrupt.');
        }

        return $data;
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create upgrade state directory: %s', $this->directory));
        }

        $file = $this->file();
        $temporary = $file . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write upgrade state.');
        }
        chmod($temporary, 0640);

        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace upgrade state.');
        }
    }

    private function file(): string
    {
        return rtrim($this->directory, '/') . '/state.json';
    }
}
