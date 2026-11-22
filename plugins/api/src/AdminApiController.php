<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Api\ApiAuthenticator;
use Core\Api\ApiTokenRepository;
use Core\Content\Comment;
use Core\Content\CommentRepository;
use Core\Content\PostAdminRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Status\SiteStatus;
use Core\Version\Version;
use Throwable;

/**
 * 需要 Bearer 令牌的管理 API。
 *
 * 所有响应都带 no-store，不返回留言邮箱等不必要字段。
 */
final class AdminApiController
{
    public function __construct(private readonly Version $version) {}

    public function status(Request $request): Response
    {
        try {
            $auth = $this->auth();
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        $token = $auth->requireScope($request, 'status:read');
        if ($token instanceof Response) {
            return $token;
        }

        return Response::json([
            'data' => (new SiteStatus($this->version))->report(),
            'meta' => ['token' => $token->name],
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function posts(Request $request): Response
    {
        try {
            $db = Connection::fromEnv();
            $auth = new ApiAuthenticator(new ApiTokenRepository($db));
            $token = $auth->requireScope($request, 'content:read');
            if ($token instanceof Response) {
                return $token;
            }

            $query = $request->query();
            $status = (string) ($query['status'] ?? '');
            $status = in_array($status, ['published', 'draft', 'archived'], true) ? $status : null;
            $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 80);
            $sort = (string) ($query['sort'] ?? 'updated');
            $sort = in_array($sort, ['updated', 'published', 'title'], true) ? $sort : 'updated';
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, min(50, (int) ($query['per_page'] ?? 10)));
            $filters = ['status' => $status, 'q' => $q, 'tag' => '', 'changes' => false, 'sort' => $sort];
            $repository = new PostAdminRepository($db);
            $total = $repository->countFiltered($filters);
            $state = Pagination::resolve($page, $total, $perPage);
            $rows = $repository->paginate($filters, $state['per_page'], $state['offset']);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        $data = array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'excerpt' => (string) ($row['excerpt'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'published_at' => (string) ($row['published_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'has_draft' => trim((string) ($row['draft_updated_at'] ?? '')) !== '',
        ], $rows);

        return Response::json([
            'data' => $data,
            'meta' => [
                'total' => $state['total'],
                'page' => $state['page'],
                'pages' => $state['pages'],
                'per_page' => $state['per_page'],
                'status' => $status ?? 'all',
                'sort' => $sort,
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function comments(Request $request): Response
    {
        try {
            $db = Connection::fromEnv();
            $auth = new ApiAuthenticator(new ApiTokenRepository($db));
            $token = $auth->requireScope($request, 'comments:read');
            if ($token instanceof Response) {
                return $token;
            }

            $query = $request->query();
            $status = (string) ($query['status'] ?? '');
            $status = in_array($status, ['pending', 'approved', 'spam'], true) ? $status : null;
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, min(50, (int) ($query['per_page'] ?? 10)));
            $repository = new CommentRepository($db);
            $total = $repository->count($status);
            $state = Pagination::resolve($page, $total, $perPage);
            $comments = $repository->adminPage($status, $state['per_page'], $state['offset']);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(static fn (Comment $comment): array => [
                'id' => $comment->id,
                'post_id' => $comment->postId,
                'post_title' => $comment->postTitle,
                'post_slug' => $comment->postSlug,
                'author_name' => $comment->authorName,
                'body' => $comment->body,
                'status' => $comment->status,
                'created_at' => $comment->createdAt,
                'approved_at' => $comment->approvedAt,
            ], $comments),
            'meta' => [
                'total' => $state['total'],
                'page' => $state['page'],
                'pages' => $state['pages'],
                'per_page' => $state['per_page'],
                'status' => $status ?? 'all',
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @return ApiAuthenticator */
    private function auth(): ApiAuthenticator
    {
        return new ApiAuthenticator(new ApiTokenRepository(Connection::fromEnv()));
    }

    private function unavailable(Request $request): Response
    {
        return Response::problem([
            'type' => '/problems/service-unavailable',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => '管理接口暂时不可用。',
            'instance' => $request->path(),
        ], 503)->withHeader('Cache-Control', 'no-store');
    }
}
