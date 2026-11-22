<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Api\ApiAuthenticator;
use Core\Api\ApiToken;
use Core\Api\ApiTokenRepository;
use Core\Audit\AuditRepository;
use Core\Content\Comment;
use Core\Content\CommentRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Throwable;

/**
 * 受 Bearer 令牌保护的留言管理接口。
 *
 * 读取使用 comments:read；审核、撤回、标为垃圾和删除使用 comments:write。
 * 列表不返回留言邮箱，所有写操作写入审计。
 */
final class CommentAdminApiController
{
    public function index(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'comments:read');
            if ($token instanceof Response) {
                return $token;
            }

            $query = $request->query();
            $status = (string) ($query['status'] ?? '');
            $status = in_array($status, ['pending', 'approved', 'spam'], true) ? $status : null;
            $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 80);
            $postId = (int) ($query['post_id'] ?? 0);
            $postId = $postId > 0 ? $postId : null;
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, min(50, (int) ($query['per_page'] ?? 10)));
            $repository = new CommentRepository(Connection::fromEnv());
            $counts = $repository->statusCounts();
            $total = $repository->count($status, $q, $postId);
            $state = Pagination::resolve($page, $total, $perPage);
            $comments = $repository->adminPage($status, $state['per_page'], $state['offset'], $q, $postId);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(fn (Comment $comment): array => $this->resource($comment), $comments),
            'meta' => [
                'total' => $state['total'],
                'page' => $state['page'],
                'pages' => $state['pages'],
                'per_page' => $state['per_page'],
                'status' => $status ?? 'all',
                'q' => $q,
                'post_id' => $postId,
                'counts' => $counts,
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'comments:write');
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
            $status = (string) ($body['status'] ?? '');
            if (!in_array($status, ['pending', 'approved', 'spam'], true)) {
                return $this->invalidStatus($request);
            }

            $repository = new CommentRepository(Connection::fromEnv());
            $comment = $repository->find($id);
            if ($comment === null) {
                return $this->notFound($request);
            }
            if ($status === 'approved') {
                $repository->approve($id, null);
            } elseif ($status === 'pending') {
                $repository->setPending($id);
            } else {
                $repository->markSpam($id);
            }
            $updated = $repository->find($id);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        $this->audit('comment.' . $status, $token, ['id' => $id, 'post_id' => $comment->postId, 'via' => 'api']);

        return $updated === null
            ? $this->notFound($request)
            : Response::json(['data' => $this->resource($updated)])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'comments:write');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $repository = new CommentRepository(Connection::fromEnv());
            $comment = $repository->find($id);
            if ($comment === null) {
                return $this->notFound($request);
            }
            $deleted = $repository->delete($id);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if (!$deleted) {
            return $this->notFound($request);
        }

        $this->audit('comment.deleted', $token, ['id' => $id, 'post_id' => $comment->postId, 'via' => 'api']);

        return new Response(204, ['Cache-Control' => 'no-store'], '');
    }

    public function bulk(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'comments:write');
            if ($token instanceof Response) {
                return $token;
            }

            $body = $request->json();
            if ($body === null) {
                return $this->invalidBody($request);
            }
            $action = (string) ($body['action'] ?? '');
            if (!in_array($action, ['approved', 'pending', 'spam', 'delete'], true)) {
                return $this->invalidStatus($request);
            }
            $rawIds = $body['ids'] ?? [];
            if (!is_array($rawIds) || count($rawIds) > 100) {
                return $this->invalidIds($request);
            }
            $ids = [];
            foreach ($rawIds as $raw) {
                if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                    continue;
                }
                $id = (int) $raw;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            if ($ids === []) {
                return $this->invalidIds($request);
            }

            $repository = new CommentRepository(Connection::fromEnv());
            if ($action === 'delete') {
                $affected = $repository->deleteMany($ids);
                $event = 'comment.bulk.deleted';
            } else {
                $affected = $repository->setStatusMany($ids, $action, null);
                $event = 'comment.bulk.' . $action;
            }
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        $this->audit($event, $token, ['count' => $affected, 'ids' => $ids, 'via' => 'api']);

        return Response::json([
            'data' => [
                'action' => $action,
                'affected' => $affected,
                'ids' => $ids,
            ],
        ])->withHeader('Cache-Control', 'no-store');
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

    /** @return array<string, mixed> */
    private function resource(Comment $comment): array
    {
        $parentBody = trim((string) preg_replace('/\s+/u', ' ', $comment->parentBody));
        if (mb_strlen($parentBody) > 90) {
            $parentBody = mb_substr($parentBody, 0, 90) . '…';
        }

        return [
            'id' => $comment->id,
            'post_id' => $comment->postId,
            'post_title' => $comment->postTitle,
            'post_slug' => $comment->postSlug,
            'parent_id' => $comment->parentId,
            'parent_author' => $comment->parentAuthor,
            'parent_excerpt' => $parentBody,
            'author_name' => $comment->authorName,
            'body' => $comment->body,
            'status' => $comment->status,
            'created_at' => $comment->createdAt,
            'approved_at' => $comment->approvedAt,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function audit(string $event, ApiToken $token, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, 'api:' . $token->name, $payload);
        } catch (Throwable) {
            // 审计失败不能回滚已完成的留言操作。
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
        return $this->problem($request, 400, 'comment-body-invalid', '请求体无效', '请求体必须是 JSON 对象，并包含 status 或 action。');
    }

    private function invalidStatus(Request $request): Response
    {
        return $this->problem($request, 422, 'comment-status-invalid', '留言状态无效', 'status 只支持 pending、approved、spam；批量 action 还支持 delete。');
    }

    private function invalidIds(Request $request): Response
    {
        return $this->problem($request, 422, 'comment-ids-invalid', '留言编号无效', 'ids 必须包含至少一个有效编号，最多 100 个。');
    }

    private function notFound(Request $request): Response
    {
        return $this->problem($request, 404, 'comment-not-found', 'Comment Not Found', '留言不存在或已经被删除。');
    }

    private function unavailable(Request $request): Response
    {
        return $this->problem($request, 503, 'service-unavailable', 'Service Unavailable', '留言服务暂时不可用。');
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
