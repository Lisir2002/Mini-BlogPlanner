<?php

declare(strict_types=1);

namespace Core\Http;

interface RateLimiterInterface
{
    /**
     * 记录一次请求并返回当前窗口的剩余额度。
     *
     * 存储异常时应保持站点可用，由实现方决定是否放行。
     */
    public function hit(string $scope, string $identity, int $limit, int $window): RateLimitResult;
}
