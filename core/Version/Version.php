<?php

declare(strict_types=1);

namespace Core\Version;

use RuntimeException;

final class Version
{
    /** @var array<string, mixed> */
    private readonly array $data;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->validate($data);
        $this->data = $data;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Version file not found: %s', $path));
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException(sprintf('Version file is not readable: %s', $path));
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new RuntimeException('Version file must contain a JSON object.');
        }

        return new self($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function app(): string
    {
        return (string) $this->data['app'];
    }

    public function channel(): string
    {
        return (string) $this->data['channel'];
    }

    public function schema(): int
    {
        return (int) $this->data['schema'];
    }

    public function schemaMin(): int
    {
        return (int) $this->data['schema_min'];
    }

    public function api(): string
    {
        return (string) $this->data['api'];
    }

    public function php(): string
    {
        return (string) $this->data['php'];
    }

    public function mysql(): string
    {
        return (string) $this->data['mysql'];
    }

    /** @return array<string, string> */
    public function plugins(): array
    {
        return $this->stringMap('plugins');
    }

    /** @return array<string, string> */
    public function themes(): array
    {
        return $this->stringMap('themes');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function isCompatiblePhp(string $phpVersion): bool
    {
        return version_compare($phpVersion, ltrim($this->php(), '>='), '>=');
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): void
    {
        foreach (['app', 'channel', 'schema', 'schema_min', 'api', 'php', 'mysql'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException(sprintf('version.json is missing "%s".', $key));
            }
        }

        if (!is_string($data['app']) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $data['app'])) {
            throw new RuntimeException('version.json "app" must be a SemVer such as 1.4.0.');
        }
        if (!is_string($data['api']) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $data['api'])) {
            throw new RuntimeException('version.json "api" must be a SemVer such as 1.0.0.');
        }
        if (!in_array($data['channel'], ['stable', 'beta', 'rc'], true)) {
            throw new RuntimeException('version.json "channel" must be stable, beta or rc.');
        }
        if (!is_int($data['schema']) || $data['schema'] <= 0) {
            throw new RuntimeException('version.json "schema" must be a positive integer.');
        }
        if (!is_int($data['schema_min']) || $data['schema_min'] <= 0) {
            throw new RuntimeException('version.json "schema_min" must be a positive integer.');
        }
        if ($data['schema_min'] > $data['schema']) {
            throw new RuntimeException('version.json "schema_min" cannot be greater than "schema".');
        }
        if (!is_string($data['php']) || !preg_match('/^>=\d+\.\d+\.\d+$/', $data['php'])) {
            throw new RuntimeException('version.json "php" must look like >=8.5.0.');
        }
        if (!is_string($data['mysql']) || !preg_match('/^>=\d+\.\d+\.\d+$/', $data['mysql'])) {
            throw new RuntimeException('version.json "mysql" must look like >=9.7.4.');
        }

        foreach (['plugins', 'themes'] as $key) {
            if (!array_key_exists($key, $data) || !is_array($data[$key])) {
                throw new RuntimeException(sprintf('version.json "%s" must be an object.', $key));
            }
            foreach ($data[$key] as $name => $version) {
                if (!is_string($name) || !is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
                    throw new RuntimeException(sprintf('version.json "%s" contains an invalid SemVer.', $key));
                }
            }
        }
    }

    /** @return array<string, string> */
    private function stringMap(string $key): array
    {
        /** @var array<string, string> $map */
        $map = $this->data[$key];

        return $map;
    }
}
