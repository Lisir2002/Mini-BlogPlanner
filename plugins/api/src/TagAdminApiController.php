<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Api\ApiAuthenticator;
use Core\Api\ApiToken;
use Core\Api\ApiTokenRepository;
use Core\Audit\AuditRepository;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Throwable;

/**
 * 受 Bearer 令牌保护的标签管理接口。
 *
 * 读取使用 tags:read，创建、重命名、合并和删除使用 tags:write；
 * 所有写操作都写入审计，供后台审计页追溯。
 */
final class TagAdminApiController
{
    public function index(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'tags:read');
            if ($token instanceof Response) {
                return $token;
            }

            $query = $request->query();
            $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 60);
            $status = (string) ($query['status'] ?? 'all');
            $status = in_array($status, ['all', 'used', 'empty'], true) ? $status : 'all';
            $sort = (string) ($query['sort'] ?? 'usage');
            $sort = in_array($sort, ['usage', 'name', 'recent'], true) ? $sort : 'usage';
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, min(50, (int) ($query['per_page'] ?? 10)));
            $filters = ['q' => $q, 'status' => $status, 'sort' => $sort];
            $repository = new TagRepository(Connection::fromEnv());
            $summary = $repository->adminSummary();
            $total = $repository->adminCount($filters);
            $state = Pagination::resolve($page, $total, $perPage);
            $tags = $repository->adminPage($state['per_page'], $state['offset'], $filters);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(fn (array $tag): array => $this->resource($tag), $tags),
            'meta' => [
                'total' => $state['total'],
                'page' => $state['page'],
                'pages' => $state['pages'],
                'per_page' => $state['per_page'],
                'status' => $status,
                'sort' => $sort,
                'q' => $q,
                'summary' => $summary,
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'tags:read');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $tag = (new TagRepository(Connection::fromEnv()))->findById($id);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return $tag === null
            ? $this->notFound($request)
            : Response::json(['data' => $this->resource($tag)])->withHeader('Cache-Control', 'no-store');
    }

    public function create(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'tags:write');
            if ($token instanceof Response) {
                return $token;
            }

            $body = $request->json();
            if ($body === null) {
                return $this->invalidBody($request);
            }
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) {
                return $this->invalidName($request);
            }

            $tag = (new TagRepository(Connection::fromEnv()))->create($name);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if ($tag === null) {
            return $this->conflict($request, 'tag-name-conflict', '标签名称已存在或无法创建。');
        }

        $this->audit('tag.created', $token, ['id' => $tag['id'], 'name' => $tag['name'], 'via' => 'api']);

        return Response::json(['data' => $this->resource($tag)], 201)
            ->withHeader('Location', '/api/v1/admin/tags/' . $tag['id'])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'tags:write');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $body = $request->json();
            if ($body === null) {
                return $this->invalidBody($request);
            }
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) {
                return $this->invalidName($request);
            }

            $repository = new TagRepository(Connection::fromEnv());
            if ($repository->findById($id) === null) {
                return $this->notFound($request);
            }
            $updated = $repository->rename($id, $name);
            $tag = $updated ? $repository->findById($id) : null;
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if (!$updated) {
            return $this->conflict($request, 'tag-name-conflict', '标签名称已存在或无法更新。');
        }

        $this->audit('tag.renamed', $token, ['id' => $id, 'name' => $name, 'via' => 'api']);

        return $tag === null
            ? $this->notFound($request)
            : Response::json(['data' => $this->resource($tag)])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function merge(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'tags:write');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $body = $request->json();
            if ($body === null) {
                return $this->invalidBody($request);
            }
            $targetId = filter_var($body['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($targetId === false || $targetId === $id) {
                return $this->invalidMerge($request);
            }

            $repository = new TagRepository(Connection::fromEnv());
            if ($repository->findById($id) === null) {
                return $this->notFound($request);
            }
            $target = $repository->findById((int) $targetId);
            if ($target === null) {
                return $this->invalidMerge($request);
            }
            $merged = $repository->merge($id, (int) $targetId);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if (!$merged) {
            return $this->invalidMerge($request);
        }

        $this->audit('tag.merged', $token, ['source' => $id, 'target' => (int) $targetId, 'via' => 'api']);

        return Response::json([
            'data' => [
                'merged' => true,
                'source_id' => $id,
                'target' => $this->resource($target),
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'tags:write');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $repository = new TagRepository(Connection::fromEnv());
            $tag = $repository->findById($id);
            if ($tag === null) {
                return $this->notFound($request);
            }
            if ($tag['count'] > 0) {
                return $this->conflict($request, 'tag-not-empty', '有文章关联的标签不能删除，请先合并到其他标签。');
            }
            $deleted = $repository->delete($id);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if (!$deleted) {
            return $this->conflict($request, 'tag-not-empty', '有文章关联的标签不能删除，请先合并到其他标签。');
        }

        $this->audit('tag.deleted', $token, ['id' => $id, 'via' => 'api']);

        return new Response(204, ['Cache-Control' => 'no-store'], '');
    }

    /** @return ApiToken|Response */
    private function auth(Request $request, string $scope): ApiToken|Response
    {
        try {
            return (new ApiAuthenticator(new ApiTokenRepository(Connection::fromEnv())))->requireScope($request, $scope);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
    }

    /** @param array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string} $tag
     *  @return array<string, mixed>
     */
    private function resource(array $tag): array
    {
        return [
            'id' => $tag['id'],
            'name' => $tag['name'],
            'slug' => $tag['slug'],
            'url' => '/tags/' . $tag['slug'] . '.html',
            'post_count' => $tag['count'],
            'published_count' => $tag['published_count'],
            'status' => $tag['count'] > 0 ? 'used' : 'empty',
            'created_at' => $tag['created_at'] === '' ? null : $tag['created_at'],
            'last_used_at' => $tag['last_used_at'] === '' ? null : $tag['last_used_at'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function audit(string $event, ApiToken $token, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, 'api:' . $token->name, $payload);
        } catch (Throwable) {
            // 审计失败不能回滚已完成的标签操作。
        }
    }

    /** @param array<string, string> $params */
    private function id(array $params): ?int
    {
        $value = $params['id'] ?? '';

        return preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : null;
    }

    private function invalidBody(Request $request): Response
    {
        return $this->problem($request, 400, 'tag-body-invalid', '请求体无效', '请求体必须是 JSON 对象，并包含 name。');
    }

    private function invalidName(Request $request): Response
    {
        return $this->problem($request, 422, 'tag-name-invalid', '标签名称无效', 'name 不能为空，且不能超过 60 个字。');
    }

    private function invalidMerge(Request $request): Response
    {
        return $this->problem($request, 422, 'tag-merge-invalid', '合并目标无效', 'target_id 必须是另一个存在的标签。');
    }

    private function notFound(Request $request): Response
    {
        return $this->problem($request, 404, 'tag-not-found', 'Tag Not Found', '标签不存在或已经被删除。');
    }

    private function conflict(Request $request, string $type, string $detail): Response
    {
        return $this->problem($request, 409, $type, '标签操作冲突', $detail);
    }

    private function unavailable(Request $request): Response
    {
        return $this->problem($request, 503, 'service-unavailable', 'Service Unavailable', '标签服务暂时不可用。');
    }

    private function problem(Request $request, int $status, string $type, string $title, string $detail): Response
    {
        return Response::problem([
            'type' => '/problems/' . $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->path(),
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
