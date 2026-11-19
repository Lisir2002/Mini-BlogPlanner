<?php

declare(strict_types=1);

namespace Core\Auth;

final class Admin
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $role,
        public readonly ?string $passwordHash = null,
    ) {}
}
