<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;
use Throwable;

final class UpgradeFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode,
        public readonly string $step,
        Throwable $previous,
    ) {
        parent::__construct($message, $exitCode, $previous);
    }
}
