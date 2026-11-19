<?php

declare(strict_types=1);

namespace Core\Http;

final class Csrf
{
    private const KEY = 'csrf_token';

    public static function token(): string
    {
        $token = $_SESSION[self::KEY] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $token;
        }

        return $token;
    }

    public static function validate(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
