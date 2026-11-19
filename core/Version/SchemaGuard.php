<?php

declare(strict_types=1);

namespace Core\Version;

use Core\Database\ConnectionInterface;
use PDOException;

final class SchemaGuard
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Version $version,
    ) {}

    public function assertCompatible(): void
    {
        $actual = $this->actual();

        if ($actual === null) {
            throw new SchemaMissing();
        }
        if ($actual > $this->version->schema()) {
            throw new SchemaAhead($actual, $this->version->schema());
        }
        if ($actual < $this->version->schemaMin()) {
            throw new SchemaBehind($actual, $this->version->schemaMin());
        }
    }

    public function actual(): ?int
    {
        try {
            $row = $this->db->fetchOne('SELECT schema_version FROM schema_meta WHERE id = 1');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') {
                return null;
            }

            throw $exception;
        }

        return $row === null ? null : (int) $row['schema_version'];
    }

    public function isCompatible(): bool
    {
        try {
            $this->assertCompatible();

            return true;
        } catch (SchemaGuardException) {
            return false;
        }
    }
}
