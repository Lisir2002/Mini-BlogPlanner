<?php

declare(strict_types=1);

namespace Core\Upgrade;

use Core\Config\Env;
use Core\Database\MigrationLoader;
use Core\Database\Migrator;
use Core\Version\Version;
use RuntimeException;
use Throwable;

final class RollbackRunner
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly Migrator $migrator,
        private readonly BackupService $backups,
        private readonly RestoreService $restores,
        private readonly UpgradeLock $lock,
        private readonly AuditLog $audit,
        private readonly string $maintenanceFlag,
        private readonly string $upgradeDirectory,
    ) {}

    /** @return array<string, mixed> */
    public function rollback(string $mode, ?string $toRelease, ?string $backupId): array
    {
        if (!$this->lock->acquire()) {
            throw new UpgradeFailure(
                'Another upgrade or restore is running.',
                3,
                'rollback',
                new RuntimeException('Upgrade lock is held.'),
            );
        }

        try {
            $targetId = $toRelease ?? $this->releases->previous();
            if ($targetId === null) {
                throw new UpgradeFailure(
                    'No previous release is available for rollback.',
                    7,
                    'rollback',
                    new RuntimeException('Previous release is missing.'),
                );
            }

            $target = $this->releases->manifest($targetId, true);
            $targetVersion = Version::fromFile($target->directory . '/version.json');
            if ($targetVersion->app() !== $target->app || $targetVersion->schema() !== $target->schema) {
                throw new UpgradeFailure(
                    'Target release manifest and version.json disagree.',
                    8,
                    'rollback',
                    new RuntimeException('Release metadata is inconsistent.'),
                );
            }

            $current = $this->releases->current();
            if ($current === null || $current === $targetId) {
                throw new UpgradeFailure(
                    'Rollback target must differ from the current release.',
                    7,
                    'rollback',
                    new RuntimeException('Rollback target is invalid.'),
                );
            }

            $currentSchema = $this->migrator->schemaVersion();
            if ($currentSchema === null) {
                throw new UpgradeFailure(
                    'schema_meta is missing; cannot verify rollback.',
                    8,
                    'rollback',
                    new RuntimeException('Schema ledger is missing.'),
                );
            }

            $record = [
                'mode' => $mode,
                'from_release' => $current,
                'to_release' => $targetId,
                'schema_before' => $currentSchema,
                'schema_after' => null,
                'rollback' => true,
            ];

            if ($mode === 'release') {
                if ($targetVersion->schema() !== $currentSchema) {
                    throw new UpgradeFailure(
                        'release rollback is only valid when the upgrade changed no schema.',
                        8,
                        'rollback',
                        new RuntimeException('Schema changed; use down or restore.'),
                    );
                }
                $this->touchMaintenance();
            } elseif ($mode === 'down') {
                if ($targetVersion->schema() >= $currentSchema) {
                    throw new UpgradeFailure(
                        'down rollback requires a lower target schema.',
                        8,
                        'rollback',
                        new RuntimeException('Target schema is not lower.'),
                    );
                }

                $loader = new MigrationLoader();
                $currentDirectory = $this->releases->manifest($current, false)->directory;
                $migrations = $loader->load($currentDirectory . '/database/migrations', 'core');
                $this->assertDownable($migrations, $targetVersion->schema());

                $this->touchMaintenance();
                $this->migrator->downTo($migrations, $targetVersion->schema());
            } elseif ($mode === 'restore') {
                if ($backupId === null || !$this->backups->exists($backupId)) {
                    throw new UpgradeFailure(
                        'A valid backup id is required for restore rollback.',
                        7,
                        'rollback',
                        new RuntimeException('Backup is missing.'),
                    );
                }
                $this->touchMaintenance();
                $this->restores->restore($backupId);
            } else {
                throw new UpgradeFailure(
                    'Unknown rollback mode.',
                    7,
                    'rollback',
                    new RuntimeException('Unknown rollback mode.'),
                );
            }

            $this->releases->switchTo($targetId);
            $this->reloadRuntime();
            $actual = $this->migrator->schemaVersion();
            if ($actual === null || $actual > $targetVersion->schema() || $actual < $targetVersion->schemaMin()) {
                throw new UpgradeFailure(
                    'Rollback finished but the schema does not match the target release.',
                    8,
                    'rollback',
                    new RuntimeException('Schema guard would block the target release.'),
                );
            }

            $record['schema_after'] = $actual;
            $this->writeRollbackRecord($record);
            $this->audit->append('system.rollback.succeeded', $record);
            $this->removeMaintenance();

            return ['ok' => true, 'data' => $record];
        } catch (Throwable $exception) {
            $this->audit->append('system.rollback.failed', [
                'mode' => $mode,
                'error' => $exception->getMessage(),
            ]);

            if ($exception instanceof UpgradeFailure) {
                throw $exception;
            }

            throw new UpgradeFailure($exception->getMessage(), 7, 'rollback', $exception);
        } finally {
            $this->lock->release();
        }
    }

    /** @param list<\Core\Database\Migration> $migrations */
    private function assertDownable(array $migrations, int $targetSchema): void
    {
        foreach ($migrations as $migration) {
            if ($migration->version <= $targetSchema) {
                continue;
            }
            if ($migration->irreversible || !$migration->reversible || $migration->down === null) {
                throw new UpgradeFailure(
                    sprintf('Migration %s passed the point of no return.', $migration->identifier()),
                    8,
                    'rollback',
                    new RuntimeException('Irreversible migration.'),
                );
            }
        }
    }

    private function touchMaintenance(): void
    {
        if (is_file($this->maintenanceFlag)) {
            return;
        }
        if (!is_dir(dirname($this->maintenanceFlag))
            && !mkdir(dirname($this->maintenanceFlag), 0775, true)
            && !is_dir(dirname($this->maintenanceFlag))
        ) {
            throw new RuntimeException('Unable to create maintenance flag directory.');
        }
        file_put_contents($this->maintenanceFlag, json_encode([
            'reason' => 'rollback',
            'at' => gmdate('c'),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
        chmod($this->maintenanceFlag, 0640);
    }

    private function removeMaintenance(): void
    {
        if (is_file($this->maintenanceFlag)) {
            unlink($this->maintenanceFlag);
        }
    }

    private function reloadRuntime(): void
    {
        $command = trim((string) (Env::get('RUNTIME_RELOAD_CMD', '') ?? ''));
        if ($command === '') {
            return;
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException(sprintf(
                'Runtime reload failed with exit code %d: %s',
                $code,
                implode(' ', array_slice($output, -3)),
            ));
        }

        usleep(500000);
    }

    /** @param array<string, mixed> $record */
    private function writeRollbackRecord(array $record): void
    {
        if (!is_dir($this->upgradeDirectory)
            && !mkdir($this->upgradeDirectory, 0775, true)
            && !is_dir($this->upgradeDirectory)
        ) {
            throw new RuntimeException('Unable to create upgrade directory.');
        }

        $file = rtrim($this->upgradeDirectory, '/') . '/rollback-' . gmdate('Ymd_His') . '.json';
        file_put_contents(
            $file,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            LOCK_EX,
        );
        chmod($file, 0640);
    }
}
