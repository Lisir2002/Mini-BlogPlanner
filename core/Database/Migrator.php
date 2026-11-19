<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $appVersion,
    ) {}

    public function ensureLedger(): void
    {
        $this->db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_meta (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  schema_version BIGINT UNSIGNED NOT NULL,
  app_version VARCHAR(32) NOT NULL,
  applied_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
SQL);

        $this->db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(64) NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  checksum CHAR(64) NOT NULL,
  batch INT UNSIGNED NOT NULL,
  status ENUM('pending','running','applied','failed') NOT NULL DEFAULT 'pending',
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  error TEXT NULL,
  app_version VARCHAR(32) NOT NULL,
  UNIQUE KEY uq_scope_version (scope, version),
  KEY idx_status (status, version)
) ENGINE=InnoDB
SQL);
    }

    /** @return array<string, array<string, mixed>> */
    public function records(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT scope, version, name, checksum, status, batch FROM migrations ORDER BY version ASC'
        );

        $records = [];
        foreach ($rows as $row) {
            $records[$row['scope'] . ':' . $row['version']] = $row;
        }

        return $records;
    }

    public function schemaVersion(): ?int
    {
        $row = $this->db->fetchOne('SELECT schema_version FROM schema_meta WHERE id = 1');

        return $row === null ? null : (int) $row['schema_version'];
    }

    /** @param list<Migration> $migrations
     *  @return list<Migration>
     */
    public function pending(array $migrations): array
    {
        $records = $this->records();
        $pending = [];

        foreach ($migrations as $migration) {
            $record = $records[$migration->identifier()] ?? null;
            if ($record !== null && $record['status'] === 'applied') {
                if (!hash_equals((string) $record['checksum'], $migration->checksum)) {
                    throw new MigrationConflict($migration);
                }
                continue;
            }

            $pending[] = $migration;
        }

        return $pending;
    }

    /** @param list<Migration> $migrations */
    public function apply(array $migrations): int
    {
        $this->ensureLedger();
        $batch = $this->nextBatch();
        $records = $this->records();
        $applied = 0;

        foreach ($this->sort($migrations) as $migration) {
            $record = $records[$migration->identifier()] ?? null;

            if ($record !== null && $record['status'] === 'applied') {
                if (!hash_equals((string) $record['checksum'], $migration->checksum)) {
                    throw new MigrationConflict($migration);
                }
                continue;
            }

            $this->markRunning($migration, $batch);

            try {
                ($migration->up)($this->db);
            } catch (Throwable $exception) {
                $this->markFailed($migration, $exception);
                throw new MigrationFailure($migration, $exception);
            }

            $this->markApplied($migration);
            $this->updateSchemaMeta($migration->version);
            $applied++;
        }

        return $applied;
    }

    /** @param list<Migration> $migrations */
    public function downTo(array $migrations, int $targetSchema): int
    {
        $this->ensureLedger();
        $records = $this->records();
        $applied = 0;

        $ordered = $this->sort($migrations, true);
        foreach ($ordered as $migration) {
            if ($migration->version <= $targetSchema) {
                continue;
            }

            $record = $records[$migration->identifier()] ?? null;
            if ($record === null || $record['status'] !== 'applied') {
                continue;
            }
            if ($migration->irreversible || !$migration->reversible || $migration->down === null) {
                throw new RuntimeException(sprintf(
                    'Migration %s has passed the point of no return; restore from backup instead.',
                    $migration->identifier(),
                ));
            }

            try {
                ($migration->down)($this->db);
            } catch (Throwable $exception) {
                throw new MigrationFailure($migration, $exception);
            }

            $this->db->execute('DELETE FROM migrations WHERE scope = ? AND version = ?', [
                $migration->scope,
                $migration->version,
            ]);
            $this->updateSchemaMeta($migration->version - 1);
            $applied++;
        }

        $this->updateSchemaMeta($targetSchema);

        return $applied;
    }

    /** @param list<Migration> $migrations
     *  @return list<Migration>
     */
    private function sort(array $migrations, bool $reverse = false): array
    {
        usort($migrations, static function (Migration $a, Migration $b): int {
            $aCore = $a->scope === 'core' ? 0 : 1;
            $bCore = $b->scope === 'core' ? 0 : 1;

            return [$aCore, $a->scope, $a->version] <=> [$bCore, $b->scope, $b->version];
        });

        return $reverse ? array_reverse($migrations) : $migrations;
    }

    private function nextBatch(): int
    {
        $row = $this->db->fetchOne('SELECT COALESCE(MAX(batch), 0) AS max_batch FROM migrations');

        return $row === null ? 1 : ((int) $row['max_batch']) + 1;
    }

    private function markRunning(Migration $migration, int $batch): void
    {
        $this->db->execute(
            <<<'SQL'
INSERT INTO migrations (scope, version, name, checksum, batch, status, started_at, app_version)
VALUES (?, ?, ?, ?, ?, 'running', NOW(), ?) AS new
ON DUPLICATE KEY UPDATE
  name = new.name,
  checksum = new.checksum,
  batch = new.batch,
  status = 'running',
  started_at = NOW(),
  finished_at = NULL,
  error = NULL,
  app_version = new.app_version
SQL,
            [
                $migration->scope,
                $migration->version,
                $migration->name,
                $migration->checksum,
                $batch,
                $this->appVersion,
            ],
        );
    }

    private function markApplied(Migration $migration): void
    {
        $this->db->execute(
            <<<'SQL'
UPDATE migrations
SET status = 'applied', finished_at = NOW(), error = NULL
WHERE scope = ? AND version = ?
SQL,
            [$migration->scope, $migration->version],
        );
    }

    private function markFailed(Migration $migration, Throwable $exception): void
    {
        $this->db->execute(
            'UPDATE migrations SET status = ?, finished_at = NOW(), error = ? WHERE scope = ? AND version = ?',
            [
                'failed',
                mb_substr($exception->getMessage(), 0, 2000),
                $migration->scope,
                $migration->version,
            ],
        );
    }

    private function updateSchemaMeta(int $schemaVersion): void
    {
        $this->db->execute(
            <<<'SQL'
INSERT INTO schema_meta (id, schema_version, app_version, applied_at)
VALUES (1, ?, ?, NOW()) AS new
ON DUPLICATE KEY UPDATE
  schema_version = new.schema_version,
  app_version = new.app_version
SQL,
            [$schemaVersion, $this->appVersion],
        );
    }
}
