<?php

declare(strict_types=1);

namespace Core\Database;

use Closure;
use RuntimeException;

final class Migration
{
    public function __construct(
        public readonly string $scope,
        public readonly int $version,
        public readonly string $name,
        public readonly bool $reversible,
        public readonly bool $irreversible,
        public readonly Closure $up,
        public readonly ?Closure $down = null,
        public readonly string $file = '',
        public readonly string $checksum = '',
    ) {
        if (!preg_match('/^(core|plugin:[a-z0-9_-]+|theme:[a-z0-9_-]+)$/', $scope)) {
            throw new RuntimeException('Migration scope must be core, plugin:{id} or theme:{id}.');
        }
        if (!preg_match('/^\d{10}$/', (string) $version)) {
            throw new RuntimeException('Migration version must use YYYYMMDDNN.');
        }
        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('Migration name must be 1-120 characters.');
        }
        if ($irreversible && $reversible) {
            throw new RuntimeException('A migration cannot be both reversible and irreversible.');
        }
        if ($irreversible && $down !== null) {
            throw new RuntimeException('An irreversible migration must not define down().');
        }
        if ($reversible && $down === null) {
            throw new RuntimeException('A reversible migration must define down().');
        }
        if (!$reversible && !$irreversible && $down === null) {
            throw new RuntimeException('A migration without down() must be marked irreversible.');
        }
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Migration file not found: %s', $file));
        }

        $migration = require $file;
        if (!$migration instanceof self) {
            throw new RuntimeException(sprintf('Migration must return a Migration instance: %s', $file));
        }

        return $migration->withSource($file, (string) hash_file('sha256', $file));
    }

    public function withSource(string $file, string $checksum): self
    {
        return new self(
            scope: $this->scope,
            version: $this->version,
            name: $this->name,
            reversible: $this->reversible,
            irreversible: $this->irreversible,
            up: $this->up,
            down: $this->down,
            file: $file,
            checksum: $checksum,
        );
    }

    public function identifier(): string
    {
        return $this->scope . ':' . $this->version;
    }
}
