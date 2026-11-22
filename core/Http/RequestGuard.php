<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * 在路由前按访客、来源和令牌执行分级固定窗口限流。
 *
 * 优先使用会话 Cookie 区分访客，IP 只作高上限兜底；部署在 Cloudflare 后方时
 * 由 Nginx real_ip 还原真实访客地址。
 */
final class RequestGuard
{
    private const GENERAL_LIMIT = 1200;
    private const GENERAL_WINDOW = 60;
    private const PAGE_LIMIT = 300;
    private const PAGE_WINDOW = 60;
    private const API_IP_LIMIT = 300;
    private const API_IP_WINDOW = 60;
    private const ADMIN_API_IP_LIMIT = 600;
    private const API_TOKEN_LIMIT = 240;
    private const API_TOKEN_WINDOW = 60;
    private const ADMIN_LOGIN_POST_LIMIT = 60;
    private const ADMIN_LOGIN_WINDOW = 300;
    private const ADMIN_LOGIN_GET_LIMIT = 240;
    private const ADMIN_LIMIT = 600;
    private const ADMIN_WINDOW = 60;
    private const COMMENT_LIMIT = 10;
    private const COMMENT_WINDOW = 60;
    private const COMMENT_IP_LIMIT = 40;
    private const REACTION_LIMIT = 30;
    private const REACTION_WINDOW = 60;
    private const REACTION_IP_LIMIT = 60;
    private const WRITE_LIMIT = 30;
    private const WRITE_WINDOW = 60;
    private const WRITE_IP_LIMIT = 60;

    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $secret,
    ) {}

    public function check(Request $request, ?string $clientIp = null): ?RateLimitResult
    {
        $ip = $this->validIp($clientIp ?? (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $method = $request->method();
        $path = $request->path();
        $ipKey = hash_hmac('sha256', $ip, $this->secret);
        $visitorKey = $this->visitorKey($request) ?? $ipKey;

        foreach ($this->checks($request, $method, $path, $ipKey, $visitorKey) as $check) {
            $result = $this->limiter->hit($check['scope'], $check['identity'], $check['limit'], $check['window']);
            if (!$result->allowed) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return list<array{scope:string,identity:string,limit:int,window:int}>
     */
    private function checks(Request $request, string $method, string $path, string $ipKey, string $visitorKey): array
    {
        $checks = [];

        if (str_starts_with($path, '/api/v1/admin/')) {
            $token = $this->bearerToken($request);
            if ($token !== null) {
                $checks[] = [
                    'scope' => 'api-token',
                    'identity' => hash_hmac('sha256', $token, $this->secret),
                    'limit' => self::API_TOKEN_LIMIT,
                    'window' => self::API_TOKEN_WINDOW,
                ];
            }
            $checks[] = [
                'scope' => 'api-ip',
                'identity' => $ipKey,
                'limit' => self::ADMIN_API_IP_LIMIT,
                'window' => self::API_IP_WINDOW,
            ];
        } elseif (str_starts_with($path, '/api/')) {
            $checks[] = [
                'scope' => 'api-ip',
                'identity' => $ipKey,
                'limit' => self::API_IP_LIMIT,
                'window' => self::API_IP_WINDOW,
            ];
        } elseif (preg_match('#^/admin/login(?:\.html)?$#', $path) === 1) {
            $isPost = $method === 'POST';
            $checks[] = [
                'scope' => $isPost ? 'admin-login-post' : 'admin-login-get',
                'identity' => $ipKey,
                'limit' => $isPost ? self::ADMIN_LOGIN_POST_LIMIT : self::ADMIN_LOGIN_GET_LIMIT,
                'window' => $isPost ? self::ADMIN_LOGIN_WINDOW : self::ADMIN_WINDOW,
            ];
        } elseif (str_starts_with($path, '/admin/')) {
            $checks[] = [
                'scope' => 'admin',
                'identity' => $visitorKey,
                'limit' => self::ADMIN_LIMIT,
                'window' => self::ADMIN_WINDOW,
            ];
        } elseif ($method === 'POST' && preg_match('#^/posts/[^/]+/comments$#', $path) === 1) {
            $checks[] = [
                'scope' => 'comment',
                'identity' => $visitorKey,
                'limit' => self::COMMENT_LIMIT,
                'window' => self::COMMENT_WINDOW,
            ];
            $checks[] = [
                'scope' => 'comment-ip',
                'identity' => $ipKey,
                'limit' => self::COMMENT_IP_LIMIT,
                'window' => self::COMMENT_WINDOW,
            ];
        } elseif ($method === 'POST' && preg_match('#^/posts/[^/]+/reaction$#', $path) === 1) {
            $checks[] = [
                'scope' => 'reaction',
                'identity' => $visitorKey,
                'limit' => self::REACTION_LIMIT,
                'window' => self::REACTION_WINDOW,
            ];
            $checks[] = [
                'scope' => 'reaction-ip',
                'identity' => $ipKey,
                'limit' => self::REACTION_IP_LIMIT,
                'window' => self::REACTION_WINDOW,
            ];
        } elseif (!in_array($method, ['GET', 'HEAD'], true)) {
            $checks[] = [
                'scope' => 'write',
                'identity' => $visitorKey,
                'limit' => self::WRITE_LIMIT,
                'window' => self::WRITE_WINDOW,
            ];
            $checks[] = [
                'scope' => 'write-ip',
                'identity' => $ipKey,
                'limit' => self::WRITE_IP_LIMIT,
                'window' => self::WRITE_WINDOW,
            ];
        } else {
            $checks[] = [
                'scope' => 'page',
                'identity' => $visitorKey,
                'limit' => self::PAGE_LIMIT,
                'window' => self::PAGE_WINDOW,
            ];
        }

        $checks[] = [
            'scope' => 'general',
            'identity' => $ipKey,
            'limit' => self::GENERAL_LIMIT,
            'window' => self::GENERAL_WINDOW,
        ];

        return $checks;
    }

    private function visitorKey(Request $request): ?string
    {
        $session = $request->cookie('cream_session');
        if (!is_string($session) || preg_match('/^[A-Za-z0-9,-]{20,128}$/', $session) !== 1) {
            return null;
        }

        return hash_hmac('sha256', 'session:' . $session, $this->secret);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = trim((string) ($request->header('authorization') ?? ''));
        if ($header === '' || preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function validIp(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : 'unknown';
    }
}
