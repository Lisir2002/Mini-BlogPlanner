<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;

final class MigrationConflict extends RuntimeException
{
    public function __construct(public readonly Migration $migration)
    {
        parent::__construct(sprintf('Applied migration was modified: %s', $migration->identifier()));
    }
}
