<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;
use Throwable;

final class MigrationFailure extends RuntimeException
{
    public function __construct(public readonly Migration $migration, Throwable $previous)
    {
        parent::__construct(sprintf('Migration failed: %s', $migration->identifier()), 0, $previous);
    }
}
