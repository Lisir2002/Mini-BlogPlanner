<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Content\MediaRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Throwable;

final class MediaAdminController
{
    use AdminErrors;

    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

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

        try {
            $media = (new MediaRepository(Connection::fromEnv()))->latest(60);
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        return $this->render($admin, $media);
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

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name']) || !is_string($file['tmp_name'])) {
            return $this->adminError($request, 422, '没有收到文件。');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $error = (int) $file['error'];
            $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? '图片不能超过 5MB。'
                : '上传失败，请重试。';

            return $this->adminError($request, 422, $message);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return $this->adminError($request, 422, '上传来源无效。');
        }

        $size = filesize($file['tmp_name']);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            return $this->adminError($request, 422, '图片大小必须小于 5MB。');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            return $this->adminError($request, 422, '只支持 JPEG、PNG、WebP、GIF 图片。');
        }

        $info = getimagesize($file['tmp_name']);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) {
            return $this->adminError($request, 422, '图片尺寸无效。');
        }

        $extension = self::TYPES[$mime];
        $relativeDirectory = date('Y/m');
        $storage = rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
        $directory = $storage . '/uploads/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->adminError($request, 503, '上传目录不可写。');
        }

        $diskName = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $storage . '/uploads/' . $diskName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return $this->adminError($request, 503, '保存上传文件失败。');
        }
        @chmod($destination, 0640);

        $original = trim(basename((string) $file['name']));
        $original = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $original) ?? 'image', 0, 190);

        try {
            $id = (new MediaRepository(Connection::fromEnv()))->create(
                $diskName,
                $original,
                $mime,
                $size,
                (int) $info[0],
                (int) $info[1],
                $admin->id,
            );
            if ($id === 0) {
                throw new \RuntimeException('Media insert failed.');
            }
        } catch (Throwable) {
            @unlink($destination);

            return $this->adminError($request, 503, '保存图片记录失败。');
        }

        try {
            (new AuditRepository(Connection::fromEnv()))->append('media.uploaded', $admin->username, [
                'id' => $id,
                'mime' => $mime,
                'size' => $size,
            ]);
        } catch (Throwable) {
            // 审计失败不能撤销已经保存的图片。
        }

        return Response::redirect('/admin/media.html?uploaded=' . $id, 303);
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

        $id = (string) ($params['id'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
            return $this->adminError($request, 404, 'Not Found');
        }

        try {
            $db = Connection::fromEnv();
            $media = (new MediaRepository($db))->delete((int) $id);
        } catch (Throwable) {
            return $this->adminError($request, 503, '删除图片失败。');
        }
        if ($media === null) {
            return $this->adminError($request, 404, 'Not Found');
        }

        $storage = rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
        $uploads = realpath($storage . '/uploads');
        $path = realpath($storage . '/uploads/' . $media->diskName);
        if ($uploads !== false && $path !== false && str_starts_with($path, $uploads . DIRECTORY_SEPARATOR)) {
            @unlink($path);
        }

        try {
            (new AuditRepository(Connection::fromEnv()))->append('media.deleted', $admin->username, ['id' => $media->id]);
        } catch (Throwable) {
            // 删除已完成，审计失败不回滚文件记录。
        }

        return Response::redirect('/admin/media.html?deleted=' . $media->id, 303);
    }

    /** @param list<\Core\Content\Media> $media */
    private function render(Admin $admin, array $media): Response
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
            'uploaded' => isset($_GET['uploaded']) && ctype_digit((string) $_GET['uploaded']) ? (int) $_GET['uploaded'] : null,
        ], 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }
}
