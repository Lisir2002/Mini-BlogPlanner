<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\CommentRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Throwable;

final class CommentAdminController
{
    use AdminErrors;

    private readonly AdminGuard $guard;

    public function __construct(private readonly Theme $theme)
    {
        $this->guard = new AdminGuard($this->theme);
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $query = $request->query();
        $status = (string) ($query['status'] ?? '');
        if (!in_array($status, ['pending', 'approved', 'spam'], true)) {
            $status = '';
        }
        $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 80);
        $postId = (int) ($query['post_id'] ?? 0);
        $postId = $postId > 0 ? $postId : 0;
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = 10;

        try {
            $db = Connection::fromEnv();
            $repository = new CommentRepository($db);
            $counts = $repository->statusCounts();
            $total = $repository->count($status !== '' ? $status : null, $q, $postId > 0 ? $postId : null);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $comments = $repository->adminPage(
                $status !== '' ? $status : null,
                $limit,
                ($page - 1) * $limit,
                $q,
                $postId > 0 ? $postId : null,
            );
            $postOptions = $repository->postOptions();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法读取留言。');
        }

        $filter = array_filter([
            'status' => $status,
            'q' => $q,
            'post_id' => $postId > 0 ? $postId : null,
        ], static fn (string|int|null $value): bool => $value !== null && $value !== '');
        $url = static fn (int $target): string => '/admin/comments.html?' . http_build_query($filter + ['page' => $target]);

        $html = $this->theme->render('admin/comments', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => '留言管理',
            'current' => 'comments',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'comments' => $comments,
            'total' => $total,
            'counts' => $counts,
            'status' => $status,
            'q' => $q,
            'postId' => $postId,
            'postOptions' => $postOptions,
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $pages ? $url($page + 1) : null,
            'baseUrl' => '/admin/comments.html',
            'page_styles' => ['admin-comments.css'],
            'page_scripts' => ['admin-comments.js'],
            'ok' => (string) ($query['ok'] ?? ''),
        ], 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params): Response
    {
        return $this->moderate($request, $params, 'approved');
    }

    /** @param array<string, string> $params */
    public function spam(Request $request, array $params): Response
    {
        return $this->moderate($request, $params, 'spam');
    }

    /** @param array<string, string> $params */
    public function pending(Request $request, array $params): Response
    {
        return $this->moderate($request, $params, 'pending');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        return $this->moderate($request, $params, 'delete');
    }

    public function bulk(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['approved', 'pending', 'spam', 'delete'], true)) {
            return $this->adminError($request, 422, '请选择批量操作。');
        }
        $rawIds = $_POST['ids'] ?? [];
        $rawIds = is_array($rawIds) ? array_slice($rawIds, 0, 100) : [];
        $ids = [];
        foreach ($rawIds as $raw) {
            if (!is_string($raw) && !is_int($raw)) {
                continue;
            }
            $raw = (string) $raw;
            if (!ctype_digit($raw)) {
                continue;
            }
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return $this->redirectWith('bulk-empty');
        }

        try {
            $db = Connection::fromEnv();
            $repository = new CommentRepository($db);
            if ($action === 'delete') {
                $affected = $repository->deleteMany($ids);
                $event = 'comment.bulk.deleted';
            } else {
                $affected = $repository->setStatusMany($ids, $action, $admin->id);
                $event = 'comment.bulk.' . $action;
            }
            (new AuditRepository($db))->append($event, $admin->username, [
                'count' => $affected,
                'ids' => $ids,
            ]);
        } catch (Throwable) {
            return $this->adminError($request, 503, '批量留言操作失败，请稍后再试。');
        }

        return $this->redirectWith('bulk-' . $action);
    }

    /** @param array<string, string> $params */
    private function moderate(Request $request, array $params, string $action): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $raw = (string) ($params['id'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            return $this->adminError($request, 404);
        }
        $id = (int) $raw;

        try {
            $db = Connection::fromEnv();
            $repository = new CommentRepository($db);
            $comment = $repository->find($id);
            if ($comment === null) {
                return $this->adminError($request, 404);
            }

            if ($action === 'approved') {
                $repository->approve($id, $admin->id);
                $event = 'comment.approved';
            } elseif ($action === 'spam') {
                $repository->markSpam($id);
                $event = 'comment.spam';
            } elseif ($action === 'pending') {
                $repository->setPending($id);
                $event = 'comment.pending';
            } else {
                $repository->delete($id);
                $event = 'comment.deleted';
            }

            (new AuditRepository($db))->append($event, $admin->username, [
                'id' => $id,
                'post_id' => $comment->postId,
            ]);
        } catch (Throwable) {
            return $this->adminError($request, 503, '留言操作失败，请稍后再试。');
        }

        return $this->redirectWith($action);
    }

    private function redirectWith(string $ok): Response
    {
        $query = ['ok' => $ok];
        $status = (string) ($_POST['return_status'] ?? '');
        if (in_array($status, ['pending', 'approved', 'spam'], true)) {
            $query['status'] = $status;
        }
        $q = trim((string) ($_POST['return_q'] ?? ''));
        if ($q !== '') {
            $query['q'] = mb_substr($q, 0, 80);
        }
        $postId = (int) ($_POST['return_post_id'] ?? 0);
        if ($postId > 0) {
            $query['post_id'] = $postId;
        }
        $page = (int) ($_POST['return_page'] ?? 0);
        if ($page > 1) {
            $query['page'] = $page;
        }

        return Response::redirect('/admin/comments.html?' . http_build_query($query), 303);
    }
}
