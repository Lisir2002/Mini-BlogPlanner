<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\SeriesRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use RuntimeException;
use Throwable;

final class SeriesAdminController
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
            $series = (new SeriesRepository(Connection::fromEnv()))->adminAll();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        return $this->render($admin, $series, isset($_GET['ok']) ? (string) $_GET['ok'] : null);
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

        [$values, $errors] = $this->validate(true);
        if ($errors !== []) {
            return $this->renderWithErrors($request, $admin, $errors, $values, 422);
        }

        try {
            $repository = new SeriesRepository(Connection::fromEnv());
            $id = $repository->create(
                (string) $values['name'],
                (string) $values['slug'],
                (string) $values['description'],
            );
            $this->audit($admin, 'series.created', ['id' => $id, 'slug' => $values['slug']]);
        } catch (RuntimeException $exception) {
            return $this->renderWithErrors($request, $admin, [$exception->getMessage()], $values, 422);
        } catch (Throwable) {
            return $this->adminError($request, 503, '系列创建失败。');
        }

        return Response::redirect('/admin/series.html?ok=created', 303);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
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

        [$values, $errors] = $this->validate(false);
        if ($errors !== []) {
            return $this->renderWithErrors($request, $admin, $errors, $values, 422);
        }

        try {
            $updated = (new SeriesRepository(Connection::fromEnv()))->update(
                $id,
                (string) $values['name'],
                (string) $values['description'],
            );
        } catch (Throwable) {
            return $this->adminError($request, 503, '系列保存失败。');
        }
        if (!$updated) {
            return $this->adminError($request, 422, '保存失败：系列不存在或名称已重复。');
        }
        $this->audit($admin, 'series.updated', ['id' => $id, 'name' => $values['name']]);

        return Response::redirect('/admin/series.html?ok=updated', 303);
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
            $deleted = (new SeriesRepository(Connection::fromEnv()))->delete($id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '系列删除失败。');
        }
        if (!$deleted) {
            return $this->adminError($request, 422, '只能删除没有文章的系列，请先把文章移出系列。');
        }
        $this->audit($admin, 'series.deleted', ['id' => $id]);

        return Response::redirect('/admin/series.html?ok=deleted', 303);
    }

    /** @return array{0:array{name:string,slug:string,description:string},1:list<string>} */
    private function validate(bool $withSlug): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($_POST['name'] ?? ''))));
        $slug = $withSlug ? mb_strtolower(trim((string) ($_POST['slug'] ?? ''))) : '';
        $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($_POST['description'] ?? ''))));
        $errors = [];

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = '系列名必须为 1–120 个字符。';
        }
        if ($description !== '' && mb_strlen($description) > 500) {
            $errors[] = '系列说明不能超过 500 个字符。';
        }
        if ($withSlug && $slug !== ''
            && (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80)) {
            $errors[] = 'slug 只能是小写字母、数字和单个短横线，最长 80 位。';
        }

        return [[
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        ], $errors];
    }

    /** @param array<string, string> $params */
    private function id(array $params): ?int
    {
        $value = $params['id'] ?? '';

        return preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : null;
    }

    /** @param list<string> $errors
     *  @param array<string, string> $values
     */
    private function renderWithErrors(Request $request, Admin $admin, array $errors, array $values, int $status): Response
    {
        try {
            $series = (new SeriesRepository(Connection::fromEnv()))->adminAll();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        return $this->render($admin, $series, null, $errors, $values, $status);
    }

    /** @param list<array{id:int,name:string,slug:string,description:string,count:int}> $series
     *  @param list<string> $errors
     *  @param array<string, string> $values
     */
    private function render(
        Admin $admin,
        array $series,
        ?string $ok,
        array $errors = [],
        array $values = [],
        int $status = 200,
    ): Response {
        $html = $this->theme->render('admin/series', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => '系列管理',
            'current' => 'series',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'series' => $series,
            'ok' => $ok,
            'errors' => $errors,
            'values' => $values,
        ], 'admin/layout');

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $payload */
    private function audit(Admin $admin, string $event, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, $admin->username, $payload);
        } catch (Throwable) {
            // 审计失败不能回滚已完成的系列操作。
        }
    }
}
