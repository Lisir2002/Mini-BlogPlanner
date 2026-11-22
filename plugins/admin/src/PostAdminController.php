<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\CommentRepository;
use Core\Content\Post;
use Core\Content\PostAdminRepository;
use Core\Content\PostConflictException;
use Core\Content\PostDraftConflictException;
use Core\Content\PostDraftRepository;
use Core\Content\PostRevisionRepository;
use Core\Content\ReactionRepository;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Database\ConnectionInterface;
use Core\Http\AdminFlash;
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
    private readonly ContentHealth $health;

    public function __construct(
        private readonly Theme $theme,
        private readonly Version $version,
    ) {
        $this->guard = new AdminGuard($this->theme);
        $this->renderer = new BodyRenderer();
        $this->health = new ContentHealth();
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $query = $request->query();
        $flash = AdminFlash::pull('posts') ?? [];
        $status = (string) ($query['status'] ?? '');
        $status = in_array($status, ['published', 'draft', 'archived'], true) ? $status : null;
        $q = trim((string) ($query['q'] ?? ''));
        $q = mb_substr($q, 0, 80);
        $tag = trim((string) ($query['tag'] ?? ''));
        $sort = (string) ($query['sort'] ?? 'updated');
        $sort = in_array($sort, ['updated', 'published', 'title'], true) ? $sort : 'updated';
        $changes = (string) ($query['changes'] ?? '') === '1';
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = 10;

        try {
            $db = Connection::fromEnv();
            $repository = new PostAdminRepository($db);

            $allTags = (new TagRepository($db))->adminAll();
            if ($tag !== '' && !in_array($tag, array_column($allTags, 'slug'), true)) {
                $tag = '';
            }

            $filters = [
                'status' => $status,
                'q' => $q,
                'tag' => $tag,
                'changes' => $changes,
                'sort' => $sort,
            ];

            $summary = $repository->summary();
            $total = $repository->countFiltered($filters);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $posts = $repository->paginate($filters, $limit, ($page - 1) * $limit);
            $ids = array_map(static fn (array $post): int => (int) ($post['id'] ?? 0), $posts);
            $reactions = (new ReactionRepository($db))->summariesForPosts($ids);
            $postTags = (new TagRepository($db))->tagsForPosts($ids);
            $commentCounts = (new CommentRepository($db))->countsForPosts($ids);
            $pendingComments = (new CommentRepository($db))->count('pending');
        } catch (Throwable) {
            return $this->adminError($request, 503, '文章列表暂时不可用，请稍后重试。');
        }

        $posts = array_map(static function (array $post) use ($reactions, $postTags, $commentCounts): array {
            $id = (int) ($post['id'] ?? 0);
            $post['tags'] = $postTags[$id] ?? [];
            $comments = $commentCounts[$id] ?? ['approved' => 0, 'pending' => 0, 'spam' => 0];
            $post['comment_approved'] = (int) $comments['approved'];
            $post['comment_pending'] = (int) $comments['pending'];
            $post['comment_spam'] = (int) $comments['spam'];
            $post['reaction_likes'] = (int) ($reactions[$id]['likes'] ?? 0);
            $post['reaction_dislikes'] = (int) ($reactions[$id]['dislikes'] ?? 0);
            $post['has_changes'] = (string) ($post['status'] ?? '') === 'published'
                && trim((string) ($post['draft_updated_at'] ?? '')) !== '';

            return $post;
        }, $posts);

        $baseParams = array_filter([
            'q' => $q !== '' ? $q : null,
            'tag' => $tag !== '' ? $tag : null,
            'sort' => $sort !== 'updated' ? $sort : null,
            'changes' => $changes ? '1' : null,
        ], static fn (?string $value): bool => $value !== null);

        $listParams = $baseParams;
        if ($status !== null) {
            $listParams['status'] = $status;
        }
        $listParams = array_filter($listParams, static fn (?string $value): bool => $value !== null);

        $pageUrl = static function (int $target) use ($baseParams, $status): string {
            $params = $baseParams;
            if ($status !== null) {
                $params['status'] = $status;
            }
            if ($target > 1) {
                $params['page'] = (string) $target;
            }

            return '/admin/posts.html' . ($params !== [] ? '?' . http_build_query($params) : '');
        };

        $statusUrl = static function (?string $next) use ($baseParams): string {
            $params = $baseParams;
            if ($next === 'draft' || $next === 'archived') {
                unset($params['changes']);
            }
            if ($next !== null) {
                $params['status'] = $next;
            }

            return '/admin/posts.html' . ($params !== [] ? '?' . http_build_query($params) : '');
        };

        $changesUrl = static function (bool $onlyChanges) use ($baseParams, $status): string {
            $params = $baseParams;
            unset($params['changes']);
            if ($onlyChanges) {
                $params['changes'] = '1';
            }
            if ($status !== null) {
                $params['status'] = $status;
            }

            return '/admin/posts.html' . ($params !== [] ? '?' . http_build_query($params) : '');
        };

        $returnParams = $listParams;
        if ($page > 1) {
            $returnParams['page'] = (string) $page;
        }

        $notice = (string) ($flash['notice'] ?? '');

        return $this->render('admin/posts', $admin, [
            'title' => '文章管理',
            'current' => 'posts',
            'posts' => $posts,
            'summary' => $summary,
            'allTags' => $allTags,
            'pendingComments' => $pendingComments,
            'status' => $status,
            'q' => $q,
            'tag' => $tag,
            'sort' => $sort,
            'changes' => $changes,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $pageUrl($page - 1) : null,
            'nextUrl' => $page < $pages ? $pageUrl($page + 1) : null,
            'statusUrls' => [
                'all' => $statusUrl(null),
                'published' => $statusUrl('published'),
                'draft' => $statusUrl('draft'),
                'archived' => $statusUrl('archived'),
            ],
            'returnQuery' => http_build_query($returnParams),
            'changesUrl' => $changesUrl(true),
            'clearChangesUrl' => $changesUrl(false),
            'notice' => $notice,
            'page_styles' => ['admin-posts.css'],
            'page_scripts' => ['admin-posts.js'],
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
                (new PostRevisionRepository($db))->record($newId, 1, $values, $tags, $admin->id);
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
            $repository = new PostAdminRepository($db);
            $post = $repository->find($id);
            $draft = $post === null ? null : (new PostDraftRepository($db))->find($id);
            if ($post !== null && $draft !== null) {
                $post['tags'] = implode(', ', $draft['tags']);
                $post['title'] = (string) $draft['title'];
                $post['slug'] = (string) $draft['slug'];
                $post['excerpt'] = (string) $draft['excerpt'];
                $post['status'] = (string) $draft['status'];
                $post['body_format'] = (string) $draft['body_format'];
                $post['body_source'] = (string) ($draft['body_source'] ?? '');
                $post['body'] = (string) $draft['body_html'];
                $post['updated_at'] = (string) $draft['updated_at'];
                $post['has_draft'] = true;
                $post['draft_version'] = (int) $draft['draft_version'];
                $post['draft_updated_at'] = (string) $draft['updated_at'];
                $post['draft_base_version'] = (int) $draft['base_version'];
            } elseif ($post !== null) {
                $post['tags'] = implode(', ', (new TagRepository($db))->namesForPost($id));
                $post['has_draft'] = false;
                $post['draft_version'] = 0;
                $post['draft_updated_at'] = '';
            }
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }
        if ($post === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        return $this->renderEditor($admin, $post, [], '/admin/posts/' . $id . '/publish', 200, $post);
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
        $writing = ($payload['writing'] ?? false) === true && $format === 'markdown';
        $writingHtml = $writing
            ? (new BodyRenderer(new MarkdownRenderer(1, 6), new HtmlSanitizer(true)))->render('markdown', $source)['html']
            : $preview['html'];
        $headings = $this->renderer->headings($preview['html']);
        $postId = (int) ($payload['post_id'] ?? 0);
        $health = $this->healthReport([
            'title' => (string) ($payload['title'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'body_format' => $format,
            'body_source' => $source,
            'body_html' => $preview['html'],
        ], $preview, $postId > 0 ? $postId : null);

        return $this->jsonSuccess([
            'html' => $writingHtml,
            'warnings' => $preview['warnings'],
            'headings' => $headings,
            'stats' => $this->renderer->stats($preview['html']),
            'health' => $health,
        ]);
    }

    /** @param array<string, string> $params */
    public function saveDraft(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->jsonFail(['文章不存在。'], 404);
        }
        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $this->jsonFail(['页面已过期，请刷新后重试。'], 419);
        }

        $baseVersion = (int) ($payload['content_version'] ?? 0);
        if ($baseVersion < 1) {
            return $this->jsonFail(['缺少线上版本号，请刷新后重试。'], 422);
        }
        $expectedDraftVersion = max(0, (int) ($payload['draft_version'] ?? 0));
        [$values, $errors, $tags, $preview] = $this->validateDraft($payload);
        if ($errors !== []) {
            return $this->jsonFail($errors, 422);
        }

        try {
            $db = Connection::fromEnv();
            $post = (new PostAdminRepository($db))->find($id);
            if ($post === null) {
                return $this->jsonFail(['文章不存在。'], 404);
            }
            $health = $this->healthReport($values + [
                'body_source' => $values['body_source'] ?? '',
                'body_html' => $preview['html'],
            ], $preview, $id);
            $draft = (new PostDraftRepository($db))->save($id, array_replace($values, ['tags' => $tags]), $baseVersion, $expectedDraftVersion);
        } catch (PostDraftConflictException $exception) {
            return $this->jsonFail(
                ['工作稿已在另一个窗口更新，请先载入最新版本。'],
                409,
                [
                    'code' => 'draft_conflict',
                    'current' => [
                        'draft_version' => (int) ($exception->current['draft_version'] ?? 0),
                        'base_version' => (int) ($exception->current['base_version'] ?? 0),
                        'updated_at' => (string) ($exception->current['updated_at'] ?? ''),
                    ],
                ],
            );
        } catch (Throwable) {
            return $this->jsonFail(['工作稿保存失败，请稍后重试。'], 503);
        }

        $publishedVersion = (int) ($post['content_version'] ?? $baseVersion);

        return $this->jsonSuccess([
            'draft_version' => (int) ($draft['draft_version'] ?? 1),
            'base_version' => (int) ($draft['base_version'] ?? $baseVersion),
            'published_version' => $publishedVersion,
            'stale' => (int) ($draft['base_version'] ?? $baseVersion) !== $publishedVersion,
            'updated_at' => (string) ($draft['updated_at'] ?? date('Y-m-d H:i:s')),
            'health' => $health,
        ]);
    }

    /** @param array<string, string> $params */
    public function discardDraft(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->jsonFail(['文章不存在。'], 404);
        }
        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $this->jsonFail(['页面已过期，请刷新后重试。'], 419);
        }

        try {
            (new PostDraftRepository(Connection::fromEnv()))->delete($id);
            $this->audit($admin, 'post.draft_discarded', ['id' => $id]);
        } catch (Throwable) {
            return $this->jsonFail(['丢弃工作稿失败，请稍后重试。'], 503);
        }

        return $this->jsonSuccess(['discarded' => true]);
    }

    /** @param array<string, string> $params */
    public function publish(Request $request, array $params): Response
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
                ? $this->jsonFail(['缺少线上版本号，请刷新后重试。'], 422)
                : $this->renderEditor($admin, null, ['缺少线上版本号，请刷新后重试。'], '/admin/posts/' . $id . '/publish', 422);
        }

        [$values, $errors, $tags, $preview] = $this->validate($payload);
        if ($errors !== []) {
            return $request->wantsJson()
                ? $this->jsonFail($errors, 422)
                : $this->renderEditor($admin, null, $errors, '/admin/posts/' . $id . '/publish', 422, $values);
        }

        try {
            $db = Connection::fromEnv();
            $repository = new PostAdminRepository($db);
            $health = $this->healthReport($values + ['body_html' => $preview['html']], $preview, $id);
            if ($health['blockers'] !== []) {
                return $request->wantsJson()
                    ? $this->jsonFail($health['blockers'], 422, ['code' => 'blocked', 'health' => $health])
                    : $this->renderEditor($admin, null, $health['blockers'], '/admin/posts/' . $id . '/publish', 422, $values);
            }

            $newVersion = 0;
            $db->transaction(function (ConnectionInterface $tx) use ($id, $values, $tags, $admin, $expectedVersion, &$newVersion): void {
                $newVersion = (new PostAdminRepository($tx))->update($id, $values + ['author_id' => $admin->id], $expectedVersion);
                (new PostRevisionRepository($tx))->record($id, $newVersion, $values, $tags, $admin->id);
                (new TagRepository($tx))->sync($id, $tags);
                (new PostDraftRepository($tx))->delete($id);
            });
            $this->audit($admin, 'post.published', ['id' => $id, 'slug' => $values['slug'], 'status' => $values['status'], 'version' => $newVersion]);
            $current = $repository->find($id);
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
                    ['线上文章已在另一个窗口更新，请先载入最新版本。'],
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

            return $this->renderEditor($admin, $current, ['线上文章已在另一个窗口更新，已阻止覆盖。'], '/admin/posts/' . $id . '/publish', 409, $values);
        } catch (Throwable) {
            return $request->wantsJson()
                ? $this->jsonFail(['发布失败，slug 可能已存在。'], 422)
                : $this->renderEditor($admin, null, ['发布失败，slug 可能已存在。'], '/admin/posts/' . $id . '/publish', 422, $values);
        }

        if ($request->wantsJson()) {
            return $this->jsonSuccess([
                'id' => $id,
                'content_version' => $newVersion,
                'updated_at' => (string) ($current['updated_at'] ?? date('Y-m-d H:i:s')),
                'draft_cleared' => true,
                'health' => $health,
            ]);
        }

        return Response::redirect('/admin/posts.html', 303);
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
                (new PostRevisionRepository($tx))->record($id, $newVersion, $values, $tags, $admin->id);
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

        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $affected = (new PostAdminRepository(Connection::fromEnv()))->archiveMany([$id]);
            if ($affected === 0) {
                return $this->adminError($request, 404, '文章不存在，或已经在归档里。');
            }
            $this->audit($admin, 'post.archived', ['id' => $id]);
        } catch (Throwable) {
            return $this->adminError($request, 503, '归档失败。');
        }

        return $this->redirectToList($payload, 'archived', 1);
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }
        $id = $this->id($params);
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $affected = (new PostAdminRepository(Connection::fromEnv()))->restoreMany([$id]);
            if ($affected === 0) {
                return $this->adminError($request, 404, '文章不存在，或不在归档里。');
            }
            $this->audit($admin, 'post.restored', ['id' => $id]);
        } catch (Throwable) {
            return $this->adminError($request, 503, '恢复失败。');
        }

        return $this->redirectToList($payload, 'restored', 1);
    }

    public function bulk(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $payload = $this->payload();
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $action = (string) ($payload['bulk_action'] ?? '');
        if (!in_array($action, ['archive', 'restore'], true)) {
            return $this->adminError($request, 422, '未知的批量操作。');
        }

        $rawIds = $payload['ids'] ?? [];
        if (!is_array($rawIds)) {
            return $this->adminError($request, 422, '请先选择文章。');
        }
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $rawIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return $this->adminError($request, 422, '请先选择文章。');
        }
        if (count($ids) > 100) {
            return $this->adminError($request, 422, '一次最多处理 100 篇文章。');
        }

        try {
            $repository = new PostAdminRepository(Connection::fromEnv());
            $affected = $action === 'archive' ? $repository->archiveMany($ids) : $repository->restoreMany($ids);
            $event = $action === 'archive' ? 'post.bulk_archived' : 'post.bulk_restored';
            $this->audit($admin, $event, ['ids' => $ids, 'count' => $affected]);
        } catch (Throwable) {
            return $this->adminError($request, 503, $action === 'archive' ? '批量归档失败。' : '批量恢复失败。');
        }

        return $this->redirectToList($payload, $action === 'archive' ? 'bulk-archived' : 'bulk-restored', $affected);
    }

    /** @param array<string, mixed> $payload */
    private function redirectToList(array $payload, string $ok, int $count): Response
    {
        $count = max(0, min(100, $count));
        $notice = match ($ok) {
            'archived' => '已归档 1 篇。',
            'restored' => '已恢复为草稿。',
            'bulk-archived' => $count > 0 ? '已归档 ' . $count . ' 篇。' : '没有可归档的文章。',
            'bulk-restored' => $count > 0 ? '已恢复 ' . $count . ' 篇为草稿。' : '没有可恢复的文章。',
            default => '',
        };
        if ($notice !== '') {
            AdminFlash::put('posts', ['notice' => $notice]);
        }

        $params = $this->returnParams((string) ($payload['return_query'] ?? ''));

        return Response::redirect('/admin/posts.html' . ($params === [] ? '' : '?' . http_build_query($params)), 303);
    }

    /** @return array<string, string> */
    private function returnParams(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        parse_str($raw, $parsed);
        $params = [];

        $status = isset($parsed['status']) && is_string($parsed['status']) ? $parsed['status'] : '';
        if (in_array($status, ['published', 'draft', 'archived'], true)) {
            $params['status'] = $status;
        }

        $q = isset($parsed['q']) && is_string($parsed['q']) ? trim($parsed['q']) : '';
        if ($q !== '') {
            $params['q'] = mb_substr($q, 0, 80);
        }

        $tag = isset($parsed['tag']) && is_string($parsed['tag']) ? trim($parsed['tag']) : '';
        if (preg_match('/^[a-z0-9-]{1,80}$/', $tag) === 1) {
            $params['tag'] = $tag;
        }

        $sort = isset($parsed['sort']) && is_string($parsed['sort']) ? $parsed['sort'] : '';
        if (in_array($sort, ['published', 'title'], true)) {
            $params['sort'] = $sort;
        }

        if (isset($parsed['changes']) && is_string($parsed['changes']) && $parsed['changes'] === '1') {
            $params['changes'] = '1';
        }

        $page = isset($parsed['page']) && is_scalar($parsed['page']) ? (int) $parsed['page'] : 1;
        if ($page > 1) {
            $params['page'] = (string) $page;
        }

        return $params;
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
            'has_draft' => false,
            'draft_version' => 0,
            'draft_updated_at' => '',
            'draft_base_version' => 1,
        ];
        $data = array_merge($defaults, $post ?? [], $values);
        $format = in_array($data['body_format'] ?? null, ['markdown', 'html'], true) ? (string) $data['body_format'] : 'markdown';
        $source = $format === 'markdown'
            ? (string) ($data['body_source'] ?? '')
            : (string) ($data['body'] ?? '');
        $preview = $this->renderer->render($format, $source);
        $health = $this->healthReport($data + ['body_html' => $preview['html']], $preview, $post === null ? null : (int) ($post['id'] ?? 0));
        $bodyHtmlValue = $format === 'html'
            ? ((bool) ($data['has_draft'] ?? false) && trim((string) ($data['body_source'] ?? '')) !== ''
                ? (string) ($data['body_source'] ?? '')
                : (string) ($data['body'] ?? ''))
            : (string) ($data['body'] ?? '');

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
            'health' => $health,
            'health_checks' => $health['checks'],
            'health_blockers' => $health['blockers'],
            'body_html_value' => $bodyHtmlValue,
            'has_draft' => (bool) ($data['has_draft'] ?? false),
            'draft_version' => (int) ($data['draft_version'] ?? 0),
            'draft_updated_at' => (string) ($data['draft_updated_at'] ?? ''),
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
     *  @return array{0:array<string, mixed>,1:list<string>,2:list<string>,3:array{html:string,warnings:list<string>}}
     */
    private function validateDraft(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = trim((string) ($input['slug'] ?? ''));
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $format = (string) ($input['body_format'] ?? 'markdown');
        $status = (string) ($input['status'] ?? 'draft');
        $tagRaw = trim((string) ($input['tags'] ?? ''));
        $tags = TagRepository::normalize(preg_split('/[,，]/u', $tagRaw) ?: []);
        $errors = [];

        if (!in_array($format, ['markdown', 'html'], true)) {
            $errors[] = '正文格式只能是 Markdown 或 HTML。';
            $format = 'markdown';
        }
        $source = $format === 'markdown'
            ? (string) ($input['body_source'] ?? '')
            : (string) ($input['body'] ?? '');
        if (strlen($source) > 1_000_000) {
            $errors[] = '源文不能超过 1 MB。';
        }
        $preview = $this->renderer->render($format, $source);

        if (mb_strlen($title) > 200) {
            $errors[] = '标题不能超过 200 个字符。';
        }
        if (mb_strlen($slug) > 190) {
            $errors[] = '固定链接不能超过 190 位。';
        }
        if (mb_strlen($excerpt) > 500) {
            $errors[] = '摘要不能超过 500 个字符。';
        }
        if (!in_array($status, ['draft', 'published'], true)) {
            $errors[] = '状态只能是草稿或已发布。';
        }
        if (mb_strlen($tagRaw) > 500) {
            $errors[] = '标签总长度不能超过 500 个字符。';
        }

        $minutes = max(1, min(120, (int) ceil(mb_strlen(strip_tags($preview['html'])) / 500)));

        return [[
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'body' => $preview['html'],
            'body_html' => $preview['html'],
            'body_format' => $format,
            'body_source' => $source,
            'status' => $status,
            'reading_minutes' => $minutes,
            'tags' => implode(', ', $tags),
            'tags_list' => $tags,
        ], $errors, $tags, $preview];
    }

    /**
     * @param array<string, mixed> $values
     * @param array{html:string,warnings:list<string>} $preview
     * @return array{checks:list<array<string, mixed>>,blockers:list<string>,warnings:list<string>}
     */
    private function healthReport(array $values, array $preview, ?int $postId): array
    {
        $slug = (string) ($values['slug'] ?? '');
        $slugExists = false;
        if ($postId !== null && $slug !== '') {
            try {
                $slugExists = (new PostAdminRepository(Connection::fromEnv()))->slugExists($slug, $postId);
            } catch (Throwable) {
                $slugExists = false;
            }
        }

        return $this->health->inspect([
            'title' => (string) ($values['title'] ?? ''),
            'slug' => $slug,
            'excerpt' => (string) ($values['excerpt'] ?? ''),
            'body_format' => (string) ($values['body_format'] ?? 'markdown'),
            'body_source' => (string) ($values['body_source'] ?? ''),
            'body_html' => $preview['html'],
            'slug_exists' => $slugExists,
        ], $preview['warnings'], $this->renderer->headings($preview['html']));
    }

    /** @param array<string, mixed> $input
     *  @return array{0:array<string, mixed>,1:list<string>,2:list<string>,3:array{html:string,warnings:list<string>}}
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
        ], $errors, $tags, $preview];
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
