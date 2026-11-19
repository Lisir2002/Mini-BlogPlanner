<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;

final class MigrationLoader
{
    /** @return list<Migration> */
    public function load(string $directory, string $scope): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/') . '/*.php');
        if ($files === false) {
            throw new RuntimeException(sprintf('Unable to scan migrations: %s', $directory));
        }

        $migrations = [];
        foreach ($files as $file) {
            $migration = Migration::fromFile($file);
            if ($migration->scope !== $scope) {
                throw new RuntimeException(sprintf(
                    'Migration scope mismatch in %s: expected %s, got %s.',
                    $file,
                    $scope,
                    $migration->scope,
                ));
            }
            if (isset($migrations[$migration->identifier()])) {
                throw new RuntimeException(sprintf('Duplicate migration: %s', $migration->identifier()));
            }
            $migrations[$migration->identifier()] = $migration;
        }

        usort($migrations, static fn (Migration $a, Migration $b): int => $a->version <=> $b->version);

        return $migrations;
    }
}
