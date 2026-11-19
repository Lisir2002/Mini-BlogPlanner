<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class ReleaseRepository
{
    public function __construct(
        private readonly string $releasesDirectory,
        private readonly string $currentLink,
        private readonly string $previousLink,
    ) {}

    public function releasesDirectory(): string
    {
        return $this->releasesDirectory;
    }

    public function current(): ?string
    {
        return $this->readLink($this->currentLink);
    }

    public function previous(): ?string
    {
        return $this->readLink($this->previousLink);
    }

    public function has(string $releaseId): bool
    {
        return is_dir($this->path($releaseId)) && is_file($this->path($releaseId) . '/RELEASE_MANIFEST.json');
    }

    public function manifest(string $releaseId, bool $verifyFiles = false): ReleaseManifest
    {
        if (!$this->has($releaseId)) {
            throw new RuntimeException(sprintf('Release not found: %s', $releaseId));
        }

        $manifest = ReleaseManifest::fromDirectory($this->path($releaseId));
        if ($verifyFiles) {
            $manifest->verifyFiles();
        }

        return $manifest;
    }

    public function switchTo(string $releaseId): void
    {
        $manifest = $this->manifest($releaseId, true);
        $current = $this->current();

        if ($current !== null && $current !== $releaseId) {
            $this->atomicLink($this->path($current), $this->previousLink);
        }

        $this->atomicLink($manifest->directory, $this->currentLink);
    }

    /** @return list<string> */
    public function list(): array
    {
        $entries = glob(rtrim($this->releasesDirectory, '/') . '/*', GLOB_ONLYDIR);
        if ($entries === false) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            if (is_file($entry . '/RELEASE_MANIFEST.json')) {
                $ids[] = basename($entry);
            }
        }

        usort($ids, static fn (string $a, string $b): int => strcmp($b, $a));

        return $ids;
    }

    private function path(string $releaseId): string
    {
        if ($releaseId === '' || str_contains($releaseId, '..') || str_contains($releaseId, '/')) {
            throw new RuntimeException('Invalid release id.');
        }

        return rtrim($this->releasesDirectory, '/') . '/' . $releaseId;
    }

    private function readLink(string $link): ?string
    {
        if (!is_link($link)) {
            return null;
        }

        $target = readlink($link);
        if ($target === false) {
            return null;
        }

        return basename(rtrim($target, '/'));
    }

    private function atomicLink(string $target, string $link): void
    {
        $directory = dirname($link);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $temporary = $link . '.tmp.' . bin2hex(random_bytes(4));
        if (!symlink($target, $temporary)) {
            throw new RuntimeException(sprintf('Unable to create symlink: %s', $temporary));
        }

        if (!rename($temporary, $link)) {
            @unlink($temporary);
            throw new RuntimeException(sprintf('Unable to replace symlink: %s', $link));
        }
    }
}
