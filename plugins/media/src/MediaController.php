<?php

declare(strict_types=1);

namespace Plugin\Media;

use Core\Content\MediaRepository;
use Core\Config\Env;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Throwable;

final class MediaController
{
    public function __construct(
        private readonly string $root,
        private readonly Theme $theme,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $id = (string) ($params['id'] ?? '');
        $name = (string) ($params['name'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/', $id) !== 1 || preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/', $name) !== 1) {
            return ErrorPage::page($this->theme, $request, 404);
        }

        try {
            $media = (new MediaRepository(Connection::fromEnv()))->find((int) $id);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '媒体服务暂时不可用。');
        }
        if ($media === null || basename($media->diskName) !== $name) {
            return ErrorPage::page($this->theme, $request, 404);
        }

        $storage = rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
        $uploads = realpath($storage . '/uploads');
        $path = realpath($storage . '/uploads/' . $media->diskName);
        if ($uploads === false || $path === false || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return ErrorPage::page($this->theme, $request, 404);
        }

        $body = file_get_contents($path);
        if ($body === false) {
            return ErrorPage::page($this->theme, $request, 404);
        }

        return Response::binary($body, $media->mime)
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
