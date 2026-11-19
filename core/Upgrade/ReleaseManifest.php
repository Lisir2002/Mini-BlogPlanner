<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class ReleaseManifest
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(
        public readonly string $releaseId,
        public readonly string $app,
        public readonly string $channel,
        public readonly int $schema,
        public readonly string $api,
        public readonly string $commit,
        public readonly string $builtAt,
        public readonly string $artifactSha256,
        public readonly array $files,
        public readonly string $directory,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        $file = rtrim($directory, '/') . '/RELEASE_MANIFEST.json';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Release manifest not found: %s', $file));
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Release manifest must be a JSON object.');
        }

        foreach (['release_id', 'app', 'channel', 'schema', 'api', 'commit', 'built_at', 'files_sha256'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException(sprintf('Release manifest is missing "%s".', $key));
            }
        }
        if (!is_array($data['files_sha256'])) {
            throw new RuntimeException('Release manifest "files_sha256" must be an object.');
        }

        /** @var array<string, string> $files */
        $files = $data['files_sha256'];

        return new self(
            releaseId: (string) $data['release_id'],
            app: (string) $data['app'],
            channel: (string) $data['channel'],
            schema: (int) $data['schema'],
            api: (string) $data['api'],
            commit: (string) $data['commit'],
            builtAt: (string) $data['built_at'],
            artifactSha256: (string) ($data['artifact_sha256'] ?? ''),
            files: $files,
            directory: rtrim($directory, '/'),
        );
    }

    public function verifyFiles(bool $strict = true): void
    {
        $base = realpath($this->directory);
        if ($base === false) {
            throw new RuntimeException(sprintf('Release directory not found: %s', $this->directory));
        }

        foreach ($this->files as $relative => $expected) {
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                throw new RuntimeException(sprintf('Unsafe manifest path: %s', $relative));
            }

            $path = $this->directory . '/' . $relative;
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(sprintf('Manifest file escaped release: %s', $relative));
            }

            $actual = hash_file('sha256', $real);
            if ($actual === false || !hash_equals($expected, $actual)) {
                throw new RuntimeException(sprintf('Manifest checksum mismatch: %s', $relative));
            }
        }

        if ($strict && $this->files === []) {
            throw new RuntimeException('Release manifest has an empty file list.');
        }
    }
}
