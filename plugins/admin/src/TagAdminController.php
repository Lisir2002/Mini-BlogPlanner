<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Theme\Theme;
use Throwable;

final class TagAdminController
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

        $q = mb_substr(trim((string) ($request->query()['q'] ?? '')), 0, 60);
        $status = (string) ($request->query()['status'] ?? 'all');
        $status = in_array($status, ['all', 'used', 'empty'], true) ? $status : 'all';
        $sort = (string) ($request->query()['sort'] ?? 'usage');
        $sort = in_array($sort, ['usage', 'name', 'recent'], true) ? $sort : 'usage';
        $filters = ['q' => $q, 'status' => $status, 'sort' => $sort];

        try {
            $repository = new TagRepository(Connection::fromEnv());
            $summary = $repository->adminSummary();
            $total = $repository->adminCount($filters);
            $state = Pagination::resolve(max(1, (int) ($request->query()['page'] ?? 1)), $total);
            $tags = $repository->adminPage($state['per_page'], $state['offset'], $filters);
            $tagOptions = $repository->adminAll();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $pageUrl = static function (int $page) use ($filters): string {
            $query = [];
            if ($filters['q'] !== '') {
                $query['q'] = $filters['q'];
            }
            if ($filters['status'] !== 'all') {
                $query['status'] = $filters['status'];
            }
            if ($filters['sort'] !== 'usage') {
                $query['sort'] = $filters['sort'];
            }
            if ($page > 1) {
                $query['page'] = $page;
            }

            return '/admin/tags.html' . ($query === [] ? '' : '?' . http_build_query($query));
        };

        $pageLinks = [];
        foreach (Pagination::window($state['page'], $state['pages']) as $number) {
            $pageLinks[] = [
                'number' => $number,
                'url' => $number === null ? null : $pageUrl($number),
            ];
        }

        return $this->render($admin, $tags, [
            'summary' => $summary,
            'filters' => $filters,
            'tagOptions' => $tagOptions,
            'total' => $total,
            'page' => $state['page'],
            'pages' => $state['pages'],
            'page_links' => $pageLinks,
            'prev_url' => $state['page'] > 1 ? $pageUrl($state['page'] - 1) : null,
            'next_url' => $state['page'] < $state['pages'] ? $pageUrl($state['page'] + 1) : null,
            'ok' => isset($_GET['ok']) ? (string) $_GET['ok'] : null,
            'err' => isset($_GET['err']) ? (string) $_GET['err'] : null,
            'cleaned' => isset($_GET['count']) ? max(0, (int) $_GET['count']) : 0,
        ]);
    }

    public function create(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $name = $this->cleanName((string) ($_POST['name'] ?? ''));
        if ($name === null) {
            return $this->back('invalid');
        }

        try {
            $tag = (new TagRepository(Connection::fromEnv()))->create($name);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签创建失败。');
        }
        if ($tag === null) {
            return $this->back('duplicate');
        }

        $this->audit($admin, 'tag.created', ['id' => $tag['id'], 'name' => $tag['name']]);

        return $this->back('created');
    }

    /** @param array<string, string> $params */
    public function rename(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $id = $this->id($params);
        $name = $this->cleanName((string) ($_POST['name'] ?? ''));
        if ($id === null) {
            return $this->back('notfound');
        }
        if ($name === null) {
            return $this->back('invalid');
        }

        try {
            $repository = new TagRepository(Connection::fromEnv());
            if ($repository->findById($id) === null) {
                return $this->back('notfound');
            }
            $renamed = $repository->rename($id, $name);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签重命名失败。');
        }
        if (!$renamed) {
            return $this->back('duplicate');
        }
        $this->audit($admin, 'tag.renamed', ['id' => $id, 'name' => $name]);

        return $this->back('renamed');
    }

    /** @param array<string, string> $params */
    public function merge(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $id = $this->id($params);
        $target = (string) ($_POST['target_id'] ?? '');
        if ($id === null) {
            return $this->back('notfound');
        }
        if (preg_match('/^[1-9][0-9]*$/', $target) !== 1 || (int) $target === $id) {
            return $this->back('merge');
        }

        try {
            $repository = new TagRepository(Connection::fromEnv());
            if ($repository->findById($id) === null) {
                return $this->back('notfound');
            }
            if ($repository->findById((int) $target) === null) {
                return $this->back('merge');
            }
            $merged = $repository->merge($id, (int) $target);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签合并失败。');
        }
        if (!$merged) {
            return $this->back('merge');
        }
        $this->audit($admin, 'tag.merged', ['source' => $id, 'target' => (int) $target]);

        return $this->back('merged');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $id = $this->id($params);
        if ($id === null) {
            return $this->back('notfound');
        }

        try {
            $repository = new TagRepository(Connection::fromEnv());
            $tag = $repository->findById($id);
            if ($tag === null) {
                return $this->back('notfound');
            }
            if ($tag['count'] > 0) {
                return $this->back('notempty');
            }
            $deleted = $repository->delete($id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签删除失败。');
        }
        if (!$deleted) {
            return $this->back('notempty');
        }
        $this->audit($admin, 'tag.deleted', ['id' => $id]);

        return $this->back('deleted');
    }

    public function cleanup(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        try {
            $count = (new TagRepository(Connection::fromEnv()))->cleanupEmpty();
        } catch (Throwable) {
            return $this->adminError($request, 503, '空标签清理失败。');
        }
        if ($count > 0) {
            $this->audit($admin, 'tag.cleaned', ['count' => $count]);
        }

        return Response::redirect('/admin/tags.html?ok=cleaned&count=' . $count, 303);
    }

    /** @param array<string, mixed> $payload */
    private function audit(Admin $admin, string $event, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, $admin->username, $payload);
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

    private function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');

        return $name === '' || mb_strlen($name) > 60 ? null : $name;
    }

    private function back(string $result): Response
    {
        return Response::redirect('/admin/tags.html?' . (in_array($result, ['created', 'renamed', 'merged', 'deleted', 'cleaned'], true) ? 'ok=' : 'err=') . $result, 303);
    }

    /**
     * @param list<array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string}> $tags
     * @param array<string, mixed> $data
     */
    private function render(Admin $admin, array $tags, array $data): Response
    {
        $html = $this->theme->render('admin/tags', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => '标签管理',
            'current' => 'tags',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'tags' => $tags,
            'body_class' => 'admin-tags-body',
            'page_styles' => ['admin-tags.css'],
        ] + $data, 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }
}
