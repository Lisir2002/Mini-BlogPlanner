<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\Post;
use Core\Content\PostAdminRepository;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Database\ConnectionInterface;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class PostAdminController
{
    use AdminErrors;

    private readonly AdminGuard $guard;

    public function __construct(
        private readonly Theme $theme,
        private readonly Version $version,
    ) {
        $this->guard = new AdminGuard($this->theme);
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        try {
            $posts = (new PostAdminRepository(Connection::fromEnv()))->all();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        return $this->render('admin/posts', $admin, [
            'title' => '文章管理',
            'current' => 'posts',
            'posts' => $posts,
        ]);
    }

    public function createForm(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        return $this->renderEditor($admin, null, [], '/admin/posts.html');
    }

    public function create(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->renderEditor($admin, null, ['页面已过期，请刷新后重试。'], '/admin/posts.html', 419);
        }

        [$values, $errors, $tags] = $this->validate();
        if ($errors !== []) {
            return $this->renderEditor($admin, null, $errors, '/admin/posts.html', 422, $values);
        }

        $newId = 0;
        try {
            Connection::fromEnv()->transaction(function (ConnectionInterface $db) use ($values, $tags, $admin, &$newId): void {
                $newId = (new PostAdminRepository($db))->create($values + ['author_id' => $admin->id]);
                (new TagRepository($db))->sync($newId, $tags);
            });
            $this->audit($admin, 'post.created', ['id' => $newId, 'slug' => $values['slug'], 'status' => $values['status']]);
        } catch (Throwable) {
            return $this->renderEditor($admin, null, ['保存失败，slug 可能已存在。'], '/admin/posts.html', 422, $values);
        }

        return Response::redirect('/admin/posts.html', 303);
    }

    /** @param array<string, string> $params */
    public function editForm(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $db = Connection::fromEnv();
            $post = (new PostAdminRepository($db))->find($id);
            if ($post !== null) {
                $post['tags'] = implode(', ', (new TagRepository($db))->namesForPost($id));
            }
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }
        if ($post === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        return $this->renderEditor($admin, $post, [], '/admin/posts/' . $id . '.html', 200, $post);
    }

    /** @param array<string, string> $params */
    public function preview(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $db = Connection::fromEnv();
            $post = (new PostAdminRepository($db))->find($id);
            $tags = $post === null ? [] : (new TagRepository($db))->namesForPost($id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }
        if ($post === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        $model = new Post(
            (int) $post['id'],
            (string) $post['slug'],
            (string) $post['title'],
            (string) $post['excerpt'],
            (string) $post['body'],
            (string) ($post['published_at'] ?? $post['updated_at'] ?? date('Y-m-d H:i:s')),
            (string) ($post['updated_at'] ?? $post['published_at'] ?? date('Y-m-d H:i:s')),
            (int) $post['reading_minutes'],
            $tags,
        );
        $html = $this->theme->render('post', [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => (string) $this->version->schema(),
            'page_title' => $post['title'] . '（预览）',
            'current' => 'posts',
            'post' => $model,
            'preview' => true,
            'page_styles' => ['posts.css'],
        ]);

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        [$values, $errors, $tags] = $this->validate();
        if ($errors !== []) {
            return $this->renderEditor($admin, null, $errors, '/admin/posts/' . $id . '.html', 422, $values);
        }

        try {
            $db = Connection::fromEnv();
            $db->transaction(function (ConnectionInterface $tx) use ($id, $values, $tags, $admin): void {
                (new PostAdminRepository($tx))->update($id, $values + ['author_id' => $admin->id]);
                (new TagRepository($tx))->sync($id, $tags);
            });
            $this->audit($admin, 'post.updated', ['id' => $id, 'slug' => $values['slug'], 'status' => $values['status']]);
        } catch (Throwable) {
            return $this->renderEditor($admin, null, ['保存失败，slug 可能已存在。'], '/admin/posts/' . $id . '.html', 422, $values);
        }

        return Response::redirect('/admin/posts.html', 303);
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params): Response
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
            (new PostAdminRepository(Connection::fromEnv()))->archive($id);
            $this->audit($admin, 'post.archived', ['id' => $id]);
        } catch (Throwable) {
            return $this->adminError($request, 503, '归档失败。');
        }

        return Response::redirect('/admin/posts.html', 303);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, Admin $admin, array $data, int $status = 200): Response
    {
        $html = $this->theme->render($template, $data + [
            'site' => $this->site(),
            'admin' => $admin,
            'csrf' => Csrf::token(),
        ], 'admin/layout');

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, mixed>|null $post
     * @param list<string> $errors
     * @param array<string, mixed> $values
     */
    private function renderEditor(Admin $admin, ?array $post, array $errors, string $action, int $status = 200, array $values = []): Response
    {
        $data = $values !== [] ? $values : ($post ?? [
            'title' => '',
            'slug' => '',
            'excerpt' => '',
            'body' => '',
            'status' => 'draft',
            'tags' => '',
        ]);

        return $this->render('admin/editor', $admin, [
            'title' => preg_match('#^/admin/posts/[1-9]#', $action) === 1 ? '编辑文章' : '写新文章',
            'current' => 'posts',
            'post' => $post,
            'values' => $data,
            'errors' => $errors,
            'action' => $action,
        ], $status);
    }

    /** @param array<string, string> $params */
    private function id(array $params): ?int
    {
        $value = $params['id'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /** @return array{0:array<string, mixed>,1:list<string>,2:list<string>} */
    private function validate(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
        $body = (new HtmlSanitizer())->clean((string) ($_POST['body'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'draft');
        $tagRaw = trim((string) ($_POST['tags'] ?? ''));
        $tags = TagRepository::normalize(preg_split('/[,，]/u', $tagRaw) ?: []);
        $errors = [];

        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = '标题必须为 1–200 个字符。';
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($slug) > 190) {
            $errors[] = 'slug 只能是小写字母、数字和单个短横线，最长 190 位。';
        }
        if (mb_strlen($excerpt) > 500) {
            $errors[] = '摘要不能超过 500 个字符。';
        }
        if (trim($body) === '' || mb_strlen($body) > 200000) {
            $errors[] = '正文不能为空，且不能超过 200000 个字符。';
        }
        if (!in_array($status, ['draft', 'published'], true)) {
            $errors[] = '状态只能是草稿或已发布。';
        }
        if (mb_strlen($tagRaw) > 500) {
            $errors[] = '标签总长度不能超过 500 个字符。';
        }

        $minutes = max(1, min(120, (int) ceil(mb_strlen(strip_tags($body)) / 500)));

        return [[
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'body' => $body,
            'status' => $status,
            'reading_minutes' => $minutes,
            'tags' => implode(', ', $tags),
        ], $errors, $tags];
    }

    /** @param array<string, mixed> $payload */
    private function audit(Admin $admin, string $event, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, $admin->username, $payload);
        } catch (Throwable) {
            // 审计失败不能阻断已经完成的内容操作。
        }
    }

    /** @return array{title:string,description:string,language:string} */
    private function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
    }
}
