<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * 后台操作结果的单次提示。
 *
 * 操作完成后写入会话，目标页面读取一次后立即清除；刷新页面或再次打开
 * 同一地址都不会重复展示，也不会把提示内容带进可分享的网址。
 */
final class AdminFlash
{
    private const SESSION_KEY = 'admin_flash';

    /** @param array<string, mixed> $payload */
    public static function put(string $channel, array $payload): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $channels = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($channels)) {
            $channels = [];
        }
        $channels[$channel] = $payload;
        $_SESSION[self::SESSION_KEY] = $channels;
    }

    /** @return array<string, mixed>|null */
    public static function pull(string $channel): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $channels = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($channels) || !isset($channels[$channel]) || !is_array($channels[$channel])) {
            return null;
        }

        $payload = $channels[$channel];
        unset($channels[$channel]);
        if ($channels === []) {
            unset($_SESSION[self::SESSION_KEY]);
        } else {
            $_SESSION[self::SESSION_KEY] = $channels;
        }

        return $payload;
    }
}
