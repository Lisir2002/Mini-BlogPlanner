<?php

declare(strict_types=1);

namespace Core\Http;

use Closure;
use Throwable;

/**
 * 文件存储的固定窗口限流。
 *
 * 适合单机或共享内存不可用的环境；每个作用域和身份使用独立文件，
 * 文件名只保存哈希，不把 IP 或令牌明文写进路径。
 */
final class FileRateLimiter implements RateLimiterInterface
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|Closure(): int $clock */
    public function __construct(
        private readonly string $directory,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $scope, string $identity, int $limit, int $window): RateLimitResult
    {
        $limit = max(1, $limit);
        $window = max(1, $window);
        $now = max(0, ($this->clock)());
        $file = $this->file($scope, $identity);

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return RateLimitResult::permit($scope, $limit, $limit, $window);
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return RateLimitResult::permit($scope, $limit, $limit, $window);
            }

            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $start = is_array($state) && isset($state['start']) ? (int) $state['start'] : 0;
            $count = is_array($state) && isset($state['count']) ? (int) $state['count'] : 0;

            if ($start <= 0 || $now >= $start + $window) {
                $start = $now;
                $count = 0;
            }

            $count++;
            $retryAfter = max(1, $start + $window - $now);
            $allowed = $count <= $limit;

            $payload = json_encode(['start' => $start, 'count' => min($count, $limit + 1)], JSON_THROW_ON_ERROR);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $payload);
            fflush($handle);
            @chmod($file, 0600);

            if (!$allowed) {
                return RateLimitResult::deny($scope, $limit, $retryAfter);
            }

            return RateLimitResult::permit($scope, $limit, max(0, $limit - $count), $retryAfter);
        } catch (Throwable) {
            return RateLimitResult::permit($scope, $limit, $limit, $window);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function file(string $scope, string $identity): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return $this->directory . '/unavailable';
        }

        return $this->directory . '/' . hash('sha256', $scope . "\0" . $identity) . '.json';
    }
}
