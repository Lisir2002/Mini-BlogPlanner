<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\MediaRepository;
use Core\Content\MediaUploadService;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Theme\Theme;
use Throwable;

final class MediaAdminController
{
    use AdminErrors;

    private readonly AdminGuard $guard;

    public function __construct(
        private readonly string $root,
        private readonly Theme $theme,
    ) {
        $this->guard = new AdminGuard($this->theme);
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $query = $request->query();
        $filters = MediaRepository::normalizeFilters([
            'q' => (string) ($query['q'] ?? ''),
            'type' => (string) ($query['type'] ?? ''),
            'sort' => (string) ($query['sort'] ?? 'newest'),
        ]);

        try {
            $repository = new MediaRepository(Connection::fromEnv());
            $summary = $repository->summary();
            $total = $repository->adminCount($filters);
            $state = Pagination::resolve(max(1, (int) ($query['page'] ?? 1)), $total);
            $media = $repository->adminPage($filters, $state['per_page'], $state['offset']);
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $page = $state['page'];
        $pages = $state['pages'];
        $pageLinks = [];
        foreach (Pagination::window($page, $pages) as $number) {
            $pageLinks[] = [
                'number' => $number,
                'url' => $number === null ? null : $this->pageUrl($filters, $number),
            ];
        }
        $hasFilters = $filters['q'] !== '' || $filters['type'] !== '' || $filters['sort'] !== 'newest';
        $activeFilterCount = ($filters['q'] !== '' ? 1 : 0)
            + ($filters['type'] !== '' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);

        return $this->render($admin, $media, [
            'summary' => $summary,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'page_links' => $pageLinks,
            'prev_url' => $page > 1 ? $this->pageUrl($filters, $page - 1) : null,
            'next_url' => $page < $pages ? $this->pageUrl($filters, $page + 1) : null,
            'filters' => $filters,
            'has_filters' => $hasFilters,
            'active_filter_count' => $activeFilterCount,
            'return_url' => $this->pageUrl($filters, $page),
            'uploaded' => max(0, (int) ($query['uploaded'] ?? 0)),
            'failed' => max(0, (int) ($query['failed'] ?? 0)),
            'upload_error' => mb_substr(trim((string) ($query['error'] ?? '')), 0, 200),
            'deleted' => max(0, (int) ($query['deleted'] ?? 0)),
        ]);
    }

    public function upload(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $files = $_FILES['file'] ?? null;
        if (!is_array($files) || !isset($files['name'], $files['tmp_name'], $files['error'])) {
            return $this->adminError($request, 422, '没有收到文件。');
        }

        try {
            $repository = new MediaRepository(Connection::fromEnv());
            $service = new MediaUploadService($repository, $this->storageDir());
            $result = $service->storeBatch($files, $admin->id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '图片服务暂时不可用。');
        }

        foreach ($result['stored'] as $media) {
            try {
                (new AuditRepository(Connection::fromEnv()))->append('media.uploaded', $admin->username, [
                    'id' => $media->id,
                    'mime' => $media->mime,
                    'size' => $media->size,
                ]);
            } catch (Throwable) {
                // 审计失败不能撤销已经保存的图片。
            }
        }

        if ($result['stored'] === []) {
            $message = $result['errors'][0] ?? '没有收到文件。';

            return $this->adminError($request, $result['fatal'] ? 503 : 422, $message);
        }

        $query = ['uploaded' => count($result['stored'])];
        if ($result['errors'] !== []) {
            $query['failed'] = count($result['errors']);
            $query['error'] = mb_substr($result['errors'][0], 0, 200);
        }

        return Response::redirect('/admin/media.html?' . http_build_query($query), 303);
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
        if ($id === null) {
            return $this->adminError($request, 404, 'Not Found');
        }
        $name = $this->cleanName(isset($_POST['name']) ? (string) $_POST['name'] : '');
        if ($name === null) {
            return $this->adminError($request, 422, '名称需为 1–190 个字。');
        }

        try {
            $repository = new MediaRepository(Connection::fromEnv());
            $before = $repository->find($id);
            if ($before === null) {
                return $this->adminError($request, 404, 'Not Found');
            }
            $media = $repository->rename($id, $name);
        } catch (Throwable) {
            return $this->adminError($request, 503, '重命名失败，请稍后重试。');
        }
        if ($media === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            (new AuditRepository(Connection::fromEnv()))->append('media.renamed', $admin->username, [
                'id' => $id,
                'from' => $before->originalName,
                'to' => $media->originalName,
            ]);
        } catch (Throwable) {
            // 名称已保存，审计失败不回滚。
        }

        return $this->back($request);
    }

    public function bulkDelete(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $ids = $this->ids($_POST['ids'] ?? []);
        if ($ids === []) {
            return $this->adminError($request, 422, '请先选择要删除的图片。');
        }
        if (count($ids) > 100) {
            return $this->adminError($request, 422, '一次最多删除 100 张图片。');
        }

        try {
            $repository = new MediaRepository(Connection::fromEnv());
            $deleted = $repository->deleteMany($ids);
        } catch (Throwable) {
            return $this->adminError($request, 503, '批量删除失败，请稍后重试。');
        }

        foreach ($deleted as $media) {
            $this->removeFile($media);
        }

        try {
            (new AuditRepository(Connection::fromEnv()))->append('media.bulk.deleted', $admin->username, [
                'count' => count($deleted),
                'ids' => array_map(static fn (Media $item): int => $item->id, $deleted),
            ]);
        } catch (Throwable) {
            // 删除已完成，审计失败不回滚。
        }

        return $this->back($request, ['deleted' => count($deleted)]);
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
            $repository = new MediaRepository(Connection::fromEnv());
            $media = $repository->delete($id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '删除图片失败。');
        }
        if ($media === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        $this->removeFile($media);

        try {
            (new AuditRepository(Connection::fromEnv()))->append('media.deleted', $admin->username, ['id' => $media->id]);
        } catch (Throwable) {
            // 删除已完成，审计失败不回滚文件记录。
        }

        return $this->back($request, ['deleted' => 1]);
    }

    /** @param array<string, string> $params */
    private function id(array $params): ?int
    {
        $raw = (string) ($params['id'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            return null;
        }
        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /** @param mixed $raw
     *  @return list<int> */
    private function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        if ($name === '' || mb_strlen($name) > 190) {
            return null;
        }

        return $name;
    }

    /** @param array<string, string> $filters */
    private function pageUrl(array $filters, int $page): string
    {
        $params = [];
        if ($filters['q'] !== '') {
            $params['q'] = $filters['q'];
        }
        if ($filters['type'] !== '') {
            $params['type'] = $filters['type'];
        }
        if ($filters['sort'] !== 'newest') {
            $params['sort'] = $filters['sort'];
        }
        if ($page > 1) {
            $params['page'] = $page;
        }

        return '/admin/media.html' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    private function storageDir(): string
    {
        return rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
    }

    private function removeFile(Media $media): void
    {
        $storage = $this->storageDir();
        $uploads = realpath($storage . '/uploads');
        $path = realpath($storage . '/uploads/' . $media->diskName);
        if ($uploads !== false && $path !== false && str_starts_with($path, $uploads . DIRECTORY_SEPARATOR)) {
            @unlink($path);
        }
    }

    /** @param array<string, int|string> $query */
    private function back(Request $request, array $query = []): Response
    {
        $return = (string) ($_POST['return'] ?? '');
        if ($return === '' || !str_starts_with($return, '/admin/media.html') || str_contains($return, '//') || preg_match('/[\r\n]/', $return) === 1) {
            $return = '/admin/media.html';
        }
        if ($query !== []) {
            $return .= (str_contains($return, '?') ? '&' : '?') . http_build_query($query);
        }

        return Response::redirect($return, 303);
    }

    /**
     * @param list<Media> $media
     * @param array<string, mixed> $list
     */
    private function render(Admin $admin, array $media, array $list = []): Response
    {
        $html = $this->theme->render('admin/media', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => '媒体库',
            'current' => 'media',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'media' => $media,
            'body_class' => 'admin-media-body',
            'page_styles' => ['admin-media.css'],
            'page_scripts' => ['admin-media.js'],
        ] + $list, 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }
}
