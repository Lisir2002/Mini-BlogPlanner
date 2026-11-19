<?php

declare(strict_types=1);

namespace Core\Config;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read environment file.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = self::unquoteDouble(substr($value, 1, -1));
            } elseif ($value !== '' && str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                self::$values[$key] = $value;
            }
        }
    }

    /**
     * 先读应用根 .env；没有时向上查找部署根，供不可变 release 复用共享配置。
     */
    public static function loadWithFallback(string $root, int $levels = 2): void
    {
        $primary = rtrim($root, '/') . '/.env';
        if (is_file($primary)) {
            self::load($primary);

            return;
        }

        for ($level = 1; $level <= $levels; $level++) {
            $candidate = dirname($root, $level) . '/.env';
            if (is_file($candidate)) {
                self::load($candidate);

                return;
            }
        }
    }

    private static function unquoteDouble(string $value): string
    {
        $result = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];
            if ($char === '\\' && $index + 1 < $length) {
                $next = $value[$index + 1];
                if ($next === '\\' || $next === '"') {
                    $result .= $next;
                    $index++;
                    continue;
                }
            }
            $result .= $char;
        }

        return $result;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? getenv($key);

        return $value === false ? $default : (string) $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Missing required environment value: %s', $key));
        }

        return $value;
    }

    /** @param list<string> $keys */
    public static function requireAny(array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::get($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Missing required environment value: ' . implode(' / ', $keys));
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$values;
    }
}
