<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * 把限流结果转换为 429 响应。
 *
 * API 使用 problem+json，页面使用轻量自包含 HTML，不加载主题和数据库。
 */
final class RateLimitResponder
{
    public static function toResponse(Request $request, RateLimitResult $result): Response
    {
        $retryAfter = max(1, $result->retryAfter);
        $headers = [
            'Cache-Control' => 'no-store',
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $result->limit,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $retryAfter,
            'X-RateLimit-Scope' => $result->scope,
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        $response = $request->wantsJson()
            ? Response::problem([
                'type' => '/problems/rate-limited',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => '请求过于频繁，请稍后再试。',
                'instance' => $request->path(),
            ], 429)
            : Response::html(self::html($retryAfter), 429);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private static function html(int $retryAfter): string
    {
        $template = @file_get_contents(dirname(__DIR__, 2) . '/public/429.html');
        if (!is_string($template) || $template === '') {
            return '请求过于频繁，请 ' . $retryAfter . ' 秒后再试。';
        }

        return str_replace('{{retry_after}}', (string) $retryAfter, $template);
    }
}
