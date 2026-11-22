<?php

declare(strict_types=1);

namespace Core\Api;

use Core\Http\Request;
use Core\Http\Response;

/**
 * 管理 API 的 Bearer 令牌校验。
 *
 * 缺少、无效、过期、已撤销统一返回 401；令牌有效但作用域不足返回 403。
 */
final class ApiAuthenticator
{
    private const TOUCH_INTERVAL = 60;

    public function __construct(private readonly ApiTokenRepository $tokens) {}

    /** @return ApiToken|Response */
    public function requireScope(Request $request, string $scope): ApiToken|Response
    {
        $header = trim((string) ($request->header('authorization') ?? ''));
        if ($header === '') {
            return $this->problem($request, 401, 'api-token-required', '需要 API 令牌', '请求缺少 Bearer 令牌。');
        }
        if (preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches) !== 1) {
            return $this->problem($request, 401, 'api-token-invalid', 'API 令牌无效', 'Authorization 头格式应为 Bearer <token>。');
        }

        $token = $this->tokens->findActiveByRaw($matches[1]);
        if ($token === null) {
            return $this->problem($request, 401, 'api-token-invalid', 'API 令牌无效', '令牌不存在、已过期或已撤销。');
        }
        if (!$token->hasScope($scope)) {
            return $this->problem($request, 403, 'api-scope-forbidden', '权限不足', '这枚令牌没有访问该接口的作用域。');
        }

        $lastUsed = $token->lastUsedAt === null ? 0 : (int) strtotime($token->lastUsedAt);
        if ($lastUsed < time() - self::TOUCH_INTERVAL) {
            $this->tokens->touch($token->id);
        }

        return $token;
    }

    private function problem(Request $request, int $status, string $type, string $title, string $detail): Response
    {
        $response = Response::problem([
            'type' => '/problems/' . $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->path(),
        ], $status);

        $response = $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('WWW-Authenticate', 'Bearer realm="Mini-BlogPlanner API"');

        return $response;
    }
}
