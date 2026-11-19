<?php

declare(strict_types=1);

namespace Core\Version;

final class SchemaBehind extends SchemaGuardException
{
    public function __construct(int $actual, int $minimum)
    {
        parent::__construct($actual, $minimum, 'Database schema is older than this release requires.');
    }
}
