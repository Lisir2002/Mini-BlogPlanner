<?php

declare(strict_types=1);

namespace Core\Version;

use RuntimeException;

abstract class SchemaGuardException extends RuntimeException
{
    public function __construct(
        public readonly ?int $actual,
        public readonly ?int $expected,
        string $message,
    ) {
        parent::__construct($message);
    }
}
