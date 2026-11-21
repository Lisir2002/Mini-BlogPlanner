<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\PostAdminRepository;
use Core\Content\PostDraftRepository;
use Core\Content\PostRevisionRepository;
use Core\Content\TagRepository;
use Core\Content\TextDiff;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Theme\Theme;
use Throwable;

final class RevisionAdminController
{
    use AdminErrors;

    private readonly AdminGuard $guard;

    public function __construct(private readonly Theme $theme)
    {
        $this->guard = new AdminGuard($this->theme);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->postId($params);
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $db = Connection::fromEnv();
            $post = (new PostAdminRepository($db))->find($id);
            if ($post === null) {
                return $this->adminError($request, 404, 'Not Found');
            }
            $allRevisions = (new PostRevisionRepository($db))->listForPost($id);
            $total = count($allRevisions);
            $state = Pagination::resolve(max(1, (int) ($request->query()['page'] ?? 1)), $total);
            $revisions = array_slice($allRevisions, $state['offset'], $state['per_page']);
            $selected = null;
            $version = (int) ($request->query()['version'] ?? 0);
            if ($version > 0) {
                $selected = (new PostRevisionRepository($db))->find($id, $version);
            }
            if ($selected === null && $allRevisions !== []) {
                $selected = (new PostRevisionRepository($db))->find($id, (int) $allRevisions[0]['version']);
            }
            $tags = (new TagRepository($db))->namesForPost($id);
            $draft = (new PostDraftRepository($db))->find($id);
            $compareVersion = (int) ($request->query()['compare'] ?? 0);
            $compareRevision = $compareVersion > 0 && $compareVersion !== (int) ($selected['version'] ?? 0)
                ? (new PostRevisionRepository($db))->find($id, $compareVersion)
                : null;
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $onlineVersion = (int) ($post['content_version'] ?? 1);
        $base = $compareRevision ?? $post;
        $baseVersion = $compareRevision === null ? $onlineVersion : (int) ($compareRevision['version'] ?? $compareVersion);
        $baseLabel = $compareRevision === null ? '线上 v' . $onlineVersion : 'v' . $baseVersion;
        $baseTags = $compareRevision === null
            ? $tags
            : (is_array($compareRevision['tags'] ?? null) ? array_values($compareRevision['tags']) : []);
        $currentText = $this->revisionText($base);
        $selectedText = $selected === null ? '' : $this->revisionText($selected);
        $diff = $selected === null ? [] : TextDiff::lines($selectedText, $currentText);
        $fields = $selected === null ? [] : [
            $this->field('标题', (string) ($selected['title'] ?? ''), (string) ($base['title'] ?? '')),
            $this->field('固定链接', (string) ($selected['slug'] ?? ''), (string) ($base['slug'] ?? '')),
            $this->field('摘要', (string) ($selected['excerpt'] ?? ''), (string) ($base['excerpt'] ?? '')),
            $this->field('标签', implode(', ', is_array($selected['tags'] ?? null) ? $selected['tags'] : []), implode(', ', $baseTags)),
            $this->field('状态', (string) ($selected['status'] ?? ''), (string) ($base['status'] ?? '')),
            $this->field('格式', (string) ($selected['body_format'] ?? ''), (string) ($base['body_format'] ?? '')),
        ];

        $page = $state['page'];
        $pages = $state['pages'];
        $pageLinks = [];
        foreach (Pagination::window($page, $pages) as $number) {
            $pageLinks[] = [
                'number' => $number,
                'url' => $number === null ? null : '/admin/posts/' . $id . '/revisions' . ($number > 1 ? '?page=' . $number : ''),
            ];
        }

        return $this->render('admin/revisions', $admin, [
            'title' => '版本历史',
            'current' => 'posts',
            'post' => $post,
            'revisions' => $revisions,
            'revisionOptions' => $allRevisions,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'page_links' => $pageLinks,
            'prev_url' => $page > 1 ? '/admin/posts/' . $id . '/revisions' . ($page > 2 ? '?page=' . ($page - 1) : '') : null,
            'next_url' => $page < $pages ? '/admin/posts/' . $id . '/revisions?page=' . ($page + 1) : null,
            'selected' => $selected,
            'selected_version' => (int) ($selected['version'] ?? 0),
            'base_version' => $baseVersion,
            'base_label' => $baseLabel,
            'base_is_online' => $compareRevision === null,
            'compare_version' => $compareRevision === null ? 0 : $baseVersion,
            'current_text' => $currentText,
            'selected_text' => $selectedText,
            'diff' => $diff,
            'fields' => $fields,
            'draft' => $draft,
            'retention' => PostRevisionRepository::RETENTION,
        ]);
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        $id = $this->postId($params);
        $version = (int) ($params['version'] ?? 0);
        if ($id === null || $version < 1) {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'errors' => ['版本不存在。']], 404)
                : $this->adminError($request, 404, 'Not Found');
        }

        $payload = $this->payload($request);
        if (!Csrf::validate(isset($payload['_csrf']) ? (string) $payload['_csrf'] : null)) {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'errors' => ['页面已过期，请刷新后重试。']], 419)
                : $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        try {
            $db = Connection::fromEnv();
            $post = (new PostAdminRepository($db))->find($id);
            $revision = (new PostRevisionRepository($db))->find($id, $version);
            if ($post === null || $revision === null) {
                return $request->wantsJson()
                    ? Response::json(['ok' => false, 'errors' => ['版本不存在。']], 404)
                    : $this->adminError($request, 404, 'Not Found');
            }
            $draft = (new PostDraftRepository($db))->find($id);
            $status = (string) ($revision['status'] ?? 'published') === 'published' ? 'published' : 'draft';
            $values = [
                'title' => (string) ($revision['title'] ?? ''),
                'slug' => (string) ($revision['slug'] ?? ''),
                'excerpt' => (string) ($revision['excerpt'] ?? ''),
                'status' => $status,
                'body_format' => (string) ($revision['body_format'] ?? 'markdown'),
                'body_source' => (string) ($revision['body_source'] ?? ''),
                'body_html' => (string) ($revision['body_html'] ?? ''),
                'tags' => is_array($revision['tags'] ?? null) ? $revision['tags'] : [],
            ];
            $draftVersion = (new PostDraftRepository($db))->save(
                $id,
                $values,
                (int) ($post['content_version'] ?? 1),
                (int) ($draft['draft_version'] ?? 0),
            );
            try {
                (new AuditRepository($db))->append('post.revision_restored', $admin->username, [
                    'id' => $id,
                    'version' => $version,
                    'draft_version' => (int) ($draftVersion['draft_version'] ?? 0),
                ]);
            } catch (Throwable) {
                // 审计失败不能回滚已经写入的工作稿。
            }
        } catch (Throwable) {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'errors' => ['恢复失败，请稍后重试。']], 503)
                : $this->adminError($request, 503, '恢复失败，请稍后重试。');
        }

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => true,
                'draft_version' => (int) ($draftVersion['draft_version'] ?? 0),
                'redirect' => '/admin/posts/' . $id . '.html',
            ]);
        }

        return Response::redirect('/admin/posts/' . $id . '.html', 303);
    }

    /** @param array<string, mixed> $post @return string */
    private function revisionText(array $post): string
    {
        $source = $post['body_source'] ?? null;
        if (is_string($source) && trim($source) !== '') {
            return $source;
        }

        return (string) ($post['body_html'] ?? $post['body'] ?? '');
    }

    /** @return array{label:string,old:string,new:string,changed:bool} */
    private function field(string $label, string $old, string $new): array
    {
        return ['label' => $label, 'old' => $old, 'new' => $new, 'changed' => $old !== $new];
    }

    /** @param array<string, string> $params */
    private function postId(array $params): ?int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            return null;
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, Admin $admin, array $data, int $status = 200): Response
    {
        $html = $this->theme->render($template, $data + [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'admin' => $admin,
            'csrf' => Csrf::token(),
        ], 'admin/layout');

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }
}
