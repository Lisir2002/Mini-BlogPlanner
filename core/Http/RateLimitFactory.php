<?php

declare(strict_types=1);

namespace Core\Http;

use Closure;
use Core\Config\Env;
use Throwable;

/**
 * 按环境选择限流存储。
 *
 * auto 优先共享内存；共享内存不可用时回退文件，避免限流组件本身拖垮站点。
 */
final class RateLimitFactory
{
    /** @param null|Closure(): int $clock */
    public static function create(string $storage, ?Closure $clock = null): RateLimiterInterface
    {
        $driver = strtolower(trim((string) (Env::get('RATE_LIMIT_DRIVER', 'auto') ?? 'auto')));

        if ($driver === 'auto' || $driver === 'shm') {
            try {
                return new SharedMemoryRateLimiter($storage . '/rate-limit.ipc', clock: $clock);
            } catch (Throwable) {
                if ($driver === 'shm') {
                    error_log('Rate limit shared memory unavailable; using file storage.');
                }
            }
        }

        return new FileRateLimiter($storage . '/rate-limit', $clock);
    }
}
