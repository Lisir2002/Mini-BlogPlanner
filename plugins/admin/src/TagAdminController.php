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

        try {
            $tags = (new TagRepository(Connection::fromEnv()))->adminAll();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        return $this->render($admin, $tags, isset($_GET['ok']) ? (string) $_GET['ok'] : null);
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
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id === null || $name === '') {
            return $this->adminError($request, 422, '标签参数无效。');
        }

        try {
            $renamed = (new TagRepository(Connection::fromEnv()))->rename($id, $name);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签重命名失败。');
        }
        if (!$renamed) {
            return $this->adminError($request, 422, '重命名失败：名称为空或已存在。');
        }
        $this->audit($admin, 'tag.renamed', ['id' => $id, 'name' => $name]);

        return Response::redirect('/admin/tags.html?ok=renamed', 303);
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
        if ($id === null || preg_match('/^[1-9][0-9]*$/', $target) !== 1 || (int) $target === $id) {
            return $this->adminError($request, 422, '合并目标无效。');
        }

        try {
            $merged = (new TagRepository(Connection::fromEnv()))->merge($id, (int) $target);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签合并失败。');
        }
        if (!$merged) {
            return $this->adminError($request, 422, '合并失败：标签不存在。');
        }
        $this->audit($admin, 'tag.merged', ['source' => $id, 'target' => (int) $target]);

        return Response::redirect('/admin/tags.html?ok=merged', 303);
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
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $deleted = (new TagRepository(Connection::fromEnv()))->delete($id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '标签删除失败。');
        }
        if (!$deleted) {
            return $this->adminError($request, 422, '只能删除没有文章的空标签。');
        }
        $this->audit($admin, 'tag.deleted', ['id' => $id]);

        return Response::redirect('/admin/tags.html?ok=deleted', 303);
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

    /** @param list<array{id:int,name:string,slug:string,count:int}> $tags */
    private function render(Admin $admin, array $tags, ?string $ok): Response
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
            'ok' => $ok,
        ], 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }
}
