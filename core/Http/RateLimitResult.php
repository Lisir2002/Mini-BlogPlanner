<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * 一次限流判定的结果。
 *
 * 结果只携带计数信息，不包含 IP、令牌或请求内容，便于直接写入响应头。
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $scope,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {}

    public static function permit(string $scope, int $limit, int $remaining, int $retryAfter): self
    {
        return new self(true, $scope, $limit, $remaining, $retryAfter);
    }

    public static function deny(string $scope, int $limit, int $retryAfter): self
    {
        return new self(false, $scope, $limit, 0, max(1, $retryAfter));
    }
}
