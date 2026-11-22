<?php

declare(strict_types=1);

namespace Core\Http;

use Closure;
use RuntimeException;
use SysvSemaphore;
use SysvSharedMemory;
use Throwable;

/**
 * 进程间共享内存限流。
 *
 * 使用 SysV 信号量保证同一台机器上的 PHP-FPM 进程原子计数，
 * 不依赖数据库或外部服务；共享内存不可用时由工厂回退到文件实现。
 */
final class SharedMemoryRateLimiter implements RateLimiterInterface
{
    private const VAR_KEY = 1;
    private const STALE_SECONDS = 3600;

    private readonly SysvSemaphore $semaphore;
    private readonly SysvSharedMemory $memory;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|Closure(): int $clock */
    public function __construct(
        string $keyFile,
        private readonly int $maxEntries = 4000,
        ?Closure $clock = null,
    ) {
        if (!function_exists('ftok') || !function_exists('sem_get') || !function_exists('shm_attach')) {
            throw new RuntimeException('SysV shared memory is unavailable.');
        }

        $directory = dirname($keyFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the rate-limit storage directory.');
        }
        if (!is_file($keyFile) && @touch($keyFile) === false) {
            throw new RuntimeException('Unable to create the rate-limit IPC key file.');
        }
        @chmod($keyFile, 0660);

        $key = ftok($keyFile, 'r');
        $semaphore = sem_get($key, 1, 0666, true);
        if ($semaphore === false) {
            throw new RuntimeException('Unable to create the rate-limit semaphore.');
        }
        $this->semaphore = $semaphore;

        $memory = shm_attach($key, 4 * 1024 * 1024, 0666);
        if ($memory === false) {
            throw new RuntimeException('Unable to attach the rate-limit shared memory.');
        }
        $this->memory = $memory;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $scope, string $identity, int $limit, int $window): RateLimitResult
    {
        $limit = max(1, $limit);
        $window = max(1, $window);
        $now = max(0, ($this->clock)());
        $bucket = hash('sha256', $scope . "\0" . $identity);

        if (!sem_acquire($this->semaphore)) {
            return RateLimitResult::permit($scope, $limit, $limit, $window);
        }

        try {
            $data = shm_has_var($this->memory, self::VAR_KEY) ? shm_get_var($this->memory, self::VAR_KEY) : [];
            $data = is_array($data) ? $data : [];
            $entry = $data[$bucket] ?? null;
            $start = is_array($entry) && isset($entry['start']) ? (int) $entry['start'] : 0;
            $count = is_array($entry) && isset($entry['count']) ? (int) $entry['count'] : 0;

            if ($start <= 0 || $now >= $start + $window) {
                $start = $now;
                $count = 0;
            }

            $count++;
            $retryAfter = max(1, $start + $window - $now);
            $allowed = $count <= $limit;
            $data[$bucket] = ['start' => $start, 'count' => min($count, $limit + 1)];
            $data = $this->prune($data, $now);

            if (!shm_put_var($this->memory, self::VAR_KEY, $data)) {
                return RateLimitResult::permit($scope, $limit, $limit, $window);
            }

            if (!$allowed) {
                return RateLimitResult::deny($scope, $limit, $retryAfter);
            }

            return RateLimitResult::permit($scope, $limit, max(0, $limit - $count), $retryAfter);
        } catch (Throwable) {
            return RateLimitResult::permit($scope, $limit, $limit, $window);
        } finally {
            sem_release($this->semaphore);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function prune(array $data, int $now): array
    {
        if (count($data) <= $this->maxEntries) {
            return $data;
        }

        foreach ($data as $key => $entry) {
            $start = is_array($entry) && isset($entry['start']) ? (int) $entry['start'] : 0;
            if ($start > 0 && $start + self::STALE_SECONDS < $now) {
                unset($data[$key]);
            }
        }

        if (count($data) > $this->maxEntries) {
            $data = array_slice($data, -$this->maxEntries, null, true);
        }

        return $data;
    }
}
