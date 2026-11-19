<?php

declare(strict_types=1);

namespace Core\Version;

final class SchemaMissing extends SchemaGuardException
{
    public function __construct()
    {
        parent::__construct(null, null, 'Database schema ledger is missing.');
    }
}
