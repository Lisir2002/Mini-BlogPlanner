<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;

final class ReactionVisitor
{
    private const COOKIE = 'cream_reaction';

    public static function current(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $token;
    }

    public static function issue(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function visitorHash(string $token): string
    {
        return hash_hmac('sha256', $token, self::key('reaction-visitor'));
    }

    public static function ipHash(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return hash_hmac('sha256', $ip, self::key('reaction-ip'));
    }

    public static function cookie(string $token): string
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

        $parts = [
            self::COOKIE . '=' . $token,
            'Max-Age=31536000',
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private static function key(string $purpose): string
    {
        return (string) (Env::get('APP_KEY', 'cream-reactions') ?? 'cream-reactions') . ':' . $purpose;
    }
}
