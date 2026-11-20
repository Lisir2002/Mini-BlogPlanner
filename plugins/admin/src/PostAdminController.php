<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\Post;
use Core\Content\PostAdminRepository;
use Core\Content\PostConflictException;
use Core\Content\ReactionRepository;
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
    private readonly BodyRenderer $renderer;

    public function __construct(
        private readonly Theme $theme,
        private readonly Version $version,
    ) {
        $this->guard = new AdminGuard($this->theme);
        $this->renderer = new BodyRenderer();
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        try {
            $db = Connection::fromEnv();
            $posts = (new PostAdminRepository($db))->all();
            $reactions = (new ReactionRepository($db))->summariesForPosts(array_map(
                static fn (array $post): int => (int) ($post['id'] ?? 0),
                $posts,
            ));
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $posts = array_map(static function (array $post) use ($reactions): array {
            $id = (int) ($post['id'] ?? 0);
            $post['reaction_likes'] = $reactions[$id]['likes'] ?? 0;
            $post['reaction_dislikes'] = $reactions[$id]['dislikes'] ?? 0;

            return $post;
        }, $posts);

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

        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $request->wantsJson()
                ? $this->jsonFail(['页面已过期，请刷新后重试。'], 419)
                : $this->renderEditor($admin, null, ['页面已过期，请刷新后重试。'], '/admin/posts.html', 419);
        }

        [$values, $errors, $tags] = $this->validate($payload);
        if ($errors !== []) {
            return $request->wantsJson()
                ? $this->jsonFail($errors, 422)
                : $this->renderEditor($admin, null, $errors, '/admin/posts.html', 422, $values);
        }

        $newId = 0;
        try {
            Connection::fromEnv()->transaction(function (ConnectionInterface $db) use ($values, $tags, $admin, &$newId): void {
                $newId = (new PostAdminRepository($db))->create($values + ['author_id' => $admin->id]);
                (new TagRepository($db))->sync($newId, $tags);
            });
            $this->audit($admin, 'post.created', ['id' => $newId, 'slug' => $values['slug'], 'status' => $values['status']]);
        } catch (Throwable) {
            return $request->wantsJson()
                ? $this->jsonFail(['保存失败，slug 可能已存在。'], 422)
                : $this->renderEditor($admin, null, ['保存失败，slug 可能已存在。'], '/admin/posts.html', 422, $values);
        }

        if ($request->wantsJson()) {
            return $this->jsonSuccess([
                'id' => $newId,
                'content_version' => 1,
                'redirect' => '/admin/posts/' . $newId . '.html',
            ]);
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

    public function previewBody(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $this->jsonFail(['页面已过期，请刷新后重试。'], 419);
        }

        $format = (string) ($payload['format'] ?? 'markdown');
        $source = (string) ($payload['source'] ?? '');
        if (!in_array($format, ['markdown', 'html'], true)) {
            return $this->jsonFail(['不支持的正文格式。'], 400);
        }
        if (strlen($source) > 1_000_000) {
            return $this->jsonFail(['正文超过 1 MB，请拆分后再预览。'], 413);
        }

        $preview = $this->renderer->render($format, $source);

        return $this->jsonSuccess([
            'html' => $preview['html'],
            'warnings' => $preview['warnings'],
            'headings' => $this->renderer->headings($preview['html']),
            'stats' => $this->renderer->stats($preview['html']),
        ]);
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
            return $request->wantsJson()
                ? $this->jsonFail(['文章不存在。'], 404)
                : $this->adminError($request, 404, 'Not Found');
        }

        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $request->wantsJson()
                ? $this->jsonFail(['页面已过期，请刷新后重试。'], 419)
                : $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $expectedVersion = (int) ($payload['content_version'] ?? 0);
        if ($expectedVersion < 1) {
            return $request->wantsJson()
                ? $this->jsonFail(['缺少文稿版本号，请刷新后重试。'], 422)
                : $this->renderEditor($admin, null, ['缺少文稿版本号，请刷新后重试。'], '/admin/posts/' . $id . '.html', 422);
        }

        [$values, $errors, $tags] = $this->validate($payload);
        if ($errors !== []) {
            return $request->wantsJson()
                ? $this->jsonFail($errors, 422)
                : $this->renderEditor($admin, null, $errors, '/admin/posts/' . $id . '.html', 422, $values);
        }

        try {
            $db = Connection::fromEnv();
            $newVersion = 0;
            $db->transaction(function (ConnectionInterface $tx) use ($id, $values, $tags, $admin, $expectedVersion, &$newVersion): void {
                $newVersion = (new PostAdminRepository($tx))->update($id, $values + ['author_id' => $admin->id], $expectedVersion);
                (new TagRepository($tx))->sync($id, $tags);
            });
            $this->audit($admin, 'post.updated', ['id' => $id, 'slug' => $values['slug'], 'status' => $values['status'], 'version' => $newVersion]);
            $current = (new PostAdminRepository($db))->find($id);
        } catch (PostConflictException $exception) {
            $current = $exception->current;
            try {
                $current['tags'] = implode(', ', (new TagRepository(Connection::fromEnv()))->namesForPost($id));
            } catch (Throwable) {
                $current['tags'] = '';
            }
            $values['content_version'] = (int) ($current['content_version'] ?? 1);
            if ($request->wantsJson()) {
                return $this->jsonFail(
                    ['这篇文稿已在另一个窗口保存，当前版本已更新。'],
                    409,
                    [
                        'code' => 'conflict',
                        'current' => [
                            'id' => (int) ($current['id'] ?? $id),
                            'title' => (string) ($current['title'] ?? ''),
                            'content_version' => (int) ($current['content_version'] ?? 1),
                            'updated_at' => (string) ($current['updated_at'] ?? ''),
                        ],
                    ],
                );
            }

            return $this->renderEditor($admin, $current, ['这篇文稿已在另一个窗口保存，已阻止覆盖。'], '/admin/posts/' . $id . '.html', 409, $values);
        } catch (Throwable) {
            return $request->wantsJson()
                ? $this->jsonFail(['保存失败，slug 可能已存在。'], 422)
                : $this->renderEditor($admin, null, ['保存失败，slug 可能已存在。'], '/admin/posts/' . $id . '.html', 422, $values);
        }

        if ($request->wantsJson()) {
            return $this->jsonSuccess([
                'id' => $id,
                'content_version' => $newVersion,
                'updated_at' => (string) ($current['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
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
        $defaults = [
            'title' => '',
            'slug' => '',
            'excerpt' => '',
            'body' => '',
            'body_format' => 'markdown',
            'body_source' => '',
            'status' => 'draft',
            'tags' => '',
            'content_version' => 1,
            'updated_at' => '',
        ];
        $data = array_merge($defaults, $post ?? [], $values);
        $format = in_array($data['body_format'] ?? null, ['markdown', 'html'], true) ? (string) $data['body_format'] : 'markdown';
        $source = $format === 'markdown'
            ? (string) ($data['body_source'] ?? '')
            : (string) ($data['body'] ?? '');
        $preview = $this->renderer->render($format, $source);

        return $this->render('admin/editor', $admin, [
            'title' => preg_match('#^/admin/posts/[1-9]#', $action) === 1 ? '编辑文章' : '写新文章',
            'current' => 'posts',
            'post' => $post,
            'values' => $data,
            'errors' => $errors,
            'action' => $action,
            'body_format' => $format,
            'preview_html' => $preview['html'],
            'preview_headings' => $this->renderer->headings($preview['html']),
            'preview_warnings' => $preview['warnings'],
            'preview_stats' => $this->renderer->stats($preview['html']),
            'body_html_value' => $format === 'html' || $source !== '' ? (string) ($data['body'] ?? '') : '',
            'page_styles' => ['posts.css', 'editor.css'],
            'page_scripts' => ['editor.js'],
            'body_class' => 'editor-body',
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

    /** @param array<string, mixed> $input
     *  @return array{0:array<string, mixed>,1:list<string>,2:list<string>}
     */
    private function validate(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = trim((string) ($input['slug'] ?? ''));
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $format = (string) ($input['body_format'] ?? 'html');
        $status = (string) ($input['status'] ?? 'draft');
        $tagRaw = trim((string) ($input['tags'] ?? ''));
        $tags = TagRepository::normalize(preg_split('/[,，]/u', $tagRaw) ?: []);
        $errors = [];

        if (!in_array($format, ['markdown', 'html'], true)) {
            $errors[] = '正文格式只能是 Markdown 或 HTML。';
            $format = 'html';
        }

        $source = $format === 'markdown'
            ? (string) ($input['body_source'] ?? '')
            : (string) ($input['body'] ?? '');
        if (strlen($source) > 1_000_000) {
            $errors[] = '源文不能超过 1 MB。';
        }
        $preview = $this->renderer->render($format, $source);
        $body = $preview['html'];
        $bodySource = $format === 'markdown' ? $source : null;

        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = '标题必须为 1–200 个字符。';
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($slug) > 190) {
            $errors[] = 'slug 只能是小写字母、数字和单个短横线，最长 190 位。';
        }
        if (mb_strlen($excerpt) > 500) {
            $errors[] = '摘要不能超过 500 个字符。';
        }
        if (trim(strip_tags($body)) === '' || mb_strlen($body) > 200000) {
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
            'body_format' => $format,
            'body_source' => $bodySource,
            'status' => $status,
            'reading_minutes' => $minutes,
            'tags' => implode(', ', $tags),
        ], $errors, $tags];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /** @param list<string> $errors
     *  @param array<string, mixed> $extra
     */
    private function jsonFail(array $errors, int $status, array $extra = []): Response
    {
        return Response::json([
            'ok' => false,
            'errors' => $errors,
        ] + $extra, $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $data */
    private function jsonSuccess(array $data): Response
    {
        return Response::json(['ok' => true] + $data)->withHeader('Cache-Control', 'no-store');
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
