<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class PlanExpired extends RuntimeException
{
    public function __construct(string $message = 'Upgrade plan is missing, expired or no longer matches the current state.')
    {
        parent::__construct($message);
    }
}
