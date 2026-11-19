<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Throwable;

final class AuditAdminController
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
        $event = trim((string) ($query['event'] ?? ''));
        $actor = trim((string) ($query['actor'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = 50;

        try {
            $repository = new AuditRepository(Connection::fromEnv());
            $total = $repository->count($event !== '' ? $event : null, $actor !== '' ? $actor : null);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $events = $repository->search($limit, ($page - 1) * $limit, $event !== '' ? $event : null, $actor !== '' ? $actor : null);
            $eventNames = $repository->eventNames();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $filter = array_filter(['event' => $event, 'actor' => $actor], static fn (string $value): bool => $value !== '');
        $url = static fn (int $target): string => '/admin/audit.html?' . http_build_query($filter + ['page' => $target]);

        $html = $this->theme->render('admin/audit', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => '审计日志',
            'current' => 'audit',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'events' => $events,
            'eventNames' => $eventNames,
            'filter' => $filter,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $pages ? $url($page + 1) : null,
            'clearUrl' => '/admin/audit.html',
        ], 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }
}
