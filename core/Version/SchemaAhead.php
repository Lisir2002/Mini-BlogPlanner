<?php

declare(strict_types=1);

namespace Core\Version;

final class SchemaAhead extends SchemaGuardException
{
    public function __construct(int $actual, int $expected)
    {
        parent::__construct($actual, $expected, 'Database schema is newer than this release.');
    }
}
