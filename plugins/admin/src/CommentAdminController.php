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

        $status = (string) ($request->query()['status'] ?? '');
        if (!in_array($status, ['pending', 'approved', 'spam'], true)) {
            $status = '';
        }
        $page = max(1, (int) ($request->query()['page'] ?? 1));
        $limit = 10;

        try {
            $db = Connection::fromEnv();
            $repository = new CommentRepository($db);
            $total = $repository->count($status !== '' ? $status : null);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $comments = $repository->adminPage($status !== '' ? $status : null, $limit, ($page - 1) * $limit);
            $pending = $repository->count('pending');
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法读取留言。');
        }

        $filter = $status !== '' ? ['status' => $status] : [];
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
            'pending' => $pending,
            'status' => $status,
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $pages ? $url($page + 1) : null,
            'baseUrl' => '/admin/comments.html',
            'ok' => (string) ($request->query()['ok'] ?? ''),
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
    public function delete(Request $request, array $params): Response
    {
        return $this->moderate($request, $params, 'delete');
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
        $status = (string) ($_POST['status'] ?? '');

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

        $query = ['ok' => $action];
        if (in_array($status, ['pending', 'approved', 'spam'], true)) {
            $query['status'] = $status;
        }

        return Response::redirect('/admin/comments.html?' . http_build_query($query), 303);
    }
}
