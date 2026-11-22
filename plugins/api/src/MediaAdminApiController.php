<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Api\ApiAuthenticator;
use Core\Api\ApiToken;
use Core\Api\ApiTokenRepository;
use Core\Audit\AuditRepository;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\MediaRepository;
use Core\Content\MediaUploadService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Throwable;

/**
 * 受 Bearer 令牌保护的媒体管理接口。
 *
 * 读取使用 media:read，上传、重命名和删除使用 media:write。
 * 文件仍通过 /media/{id}/{name} 读取，接口只负责管理元数据。
 */
final class MediaAdminApiController
{
    public function index(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'media:read');
            if ($token instanceof Response) {
                return $token;
            }

            $query = $request->query();
            $filters = MediaRepository::normalizeFilters([
                'q' => (string) ($query['q'] ?? ''),
                'type' => (string) ($query['type'] ?? ''),
                'sort' => (string) ($query['sort'] ?? 'newest'),
            ]);
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, min(50, (int) ($query['per_page'] ?? 10)));
            $repository = new MediaRepository(Connection::fromEnv());
            $summary = $repository->summary();
            $total = $repository->adminCount($filters);
            $state = Pagination::resolve($page, $total, $perPage);
            $media = $repository->adminPage($filters, $state['per_page'], $state['offset']);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(fn (Media $item): array => $this->resource($item), $media),
            'meta' => [
                'total' => $state['total'],
                'page' => $state['page'],
                'pages' => $state['pages'],
                'per_page' => $state['per_page'],
                'q' => $filters['q'],
                'type' => $filters['type'],
                'sort' => $filters['sort'],
                'summary' => $summary,
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'media:read');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $media = (new MediaRepository(Connection::fromEnv()))->find($id);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return $media === null
            ? $this->notFound($request)
            : Response::json(['data' => $this->resource($media)])->withHeader('Cache-Control', 'no-store');
    }

    public function create(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'media:write');
            if ($token instanceof Response) {
                return $token;
            }

            $files = $_FILES['file'] ?? null;
            if (!is_array($files) || !isset($files['name'], $files['tmp_name'], $files['error'])) {
                return $this->problem($request, 400, 'media-file-required', '缺少图片', '使用 multipart/form-data 的 file 字段上传图片。');
            }
            $repository = new MediaRepository(Connection::fromEnv());
            $service = new MediaUploadService($repository, $this->storageDir());
            $result = $service->storeBatch($files, null);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        foreach ($result['stored'] as $media) {
            $this->audit('media.uploaded', $token, [
                'id' => $media->id,
                'mime' => $media->mime,
                'size' => $media->size,
                'via' => 'api',
            ]);
        }

        if ($result['stored'] === []) {
            return $this->problem(
                $request,
                $result['fatal'] ? 503 : 422,
                'media-upload-invalid',
                '图片未上传',
                $result['errors'][0] ?? '没有可用的图片文件。',
            );
        }

        return Response::json([
            'data' => array_map(fn (Media $item): array => $this->resource($item), $result['stored']),
            'meta' => [
                'uploaded' => count($result['stored']),
                'errors' => $result['errors'],
            ],
        ], 201)->withHeader('Location', '/api/v1/admin/media/' . $result['stored'][0]->id)
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'media:write');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $body = $request->json();
            if ($body === null) {
                return $this->problem($request, 400, 'media-body-invalid', '请求体无效', '请求体必须是包含 name 的 JSON 对象。');
            }
            $name = $this->cleanName((string) ($body['name'] ?? ''));
            if ($name === null) {
                return $this->problem($request, 422, 'media-name-invalid', '名称无效', '名称需为 1–190 个字。');
            }

            $repository = new MediaRepository(Connection::fromEnv());
            $before = $repository->find($id);
            if ($before === null) {
                return $this->notFound($request);
            }
            $media = $repository->rename($id, $name);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if ($media === null) {
            return $this->notFound($request);
        }

        $this->audit('media.renamed', $token, [
            'id' => $id,
            'from' => $before->originalName,
            'to' => $media->originalName,
            'via' => 'api',
        ]);

        return Response::json(['data' => $this->resource($media)])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        try {
            $token = $this->auth($request, 'media:write');
            if ($token instanceof Response) {
                return $token;
            }

            $id = $this->id($params);
            if ($id === null) {
                return $this->notFound($request);
            }
            $repository = new MediaRepository(Connection::fromEnv());
            $media = $repository->delete($id);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if ($media === null) {
            return $this->notFound($request);
        }

        $this->removeFile($media);
        $this->audit('media.deleted', $token, ['id' => $id, 'via' => 'api']);

        return new Response(204, ['Cache-Control' => 'no-store'], '');
    }

    public function bulk(Request $request): Response
    {
        try {
            $token = $this->auth($request, 'media:write');
            if ($token instanceof Response) {
                return $token;
            }

            $body = $request->json();
            if ($body === null) {
                return $this->problem($request, 400, 'media-body-invalid', '请求体无效', '请求体必须是包含 ids 的 JSON 对象。');
            }
            $rawIds = $body['ids'] ?? [];
            if (!is_array($rawIds) || count($rawIds) > 100) {
                return $this->invalidIds($request);
            }
            $ids = [];
            foreach ($rawIds as $raw) {
                if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                    continue;
                }
                $id = (int) $raw;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            if ($ids === []) {
                return $this->invalidIds($request);
            }

            $repository = new MediaRepository(Connection::fromEnv());
            $deleted = $repository->deleteMany($ids);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        foreach ($deleted as $media) {
            $this->removeFile($media);
        }
        $this->audit('media.bulk.deleted', $token, [
            'count' => count($deleted),
            'ids' => array_map(static fn (Media $item): int => $item->id, $deleted),
            'via' => 'api',
        ]);

        return Response::json([
            'data' => [
                'affected' => count($deleted),
                'ids' => array_map(static fn (Media $item): int => $item->id, $deleted),
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @return ApiToken|Response */
    private function auth(Request $request, string $scope): ApiToken|Response
    {
        try {
            return (new ApiAuthenticator(new ApiTokenRepository(Connection::fromEnv())))->requireScope($request, $scope);
        } catch (Throwable) {
            return $this->unavailable($request);
        }
    }

    /** @return array<string, mixed> */
    private function resource(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->originalName,
            'mime' => $media->mime,
            'format' => $this->format($media->mime),
            'size_bytes' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'created_at' => $media->createdAt,
            'url' => $media->url(),
            'html' => '<img src="' . $media->url() . '" alt="' . $media->originalName . '">',
        ];
    }

    private function format(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WebP',
            'image/gif' => 'GIF',
            default => 'IMAGE',
        };
    }

    private function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        if ($name === '' || mb_strlen($name) > 190) {
            return null;
        }

        return $name;
    }

    private function storageDir(): string
    {
        return rtrim((string) Env::get('STORAGE_DIR', dirname(__DIR__, 3) . '/storage'), '/');
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

    /** @param array<string, mixed> $payload */
    private function audit(string $event, ApiToken $token, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, 'api:' . $token->name, $payload);
        } catch (Throwable) {
            // 审计失败不能回滚已完成的媒体操作。
        }
    }

    /** @param array<string, string> $params */
    private function id(array $params): ?int
    {
        $value = $params['id'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function invalidIds(Request $request): Response
    {
        return $this->problem($request, 422, 'media-ids-invalid', '图片编号无效', 'ids 必须包含至少一个有效编号，最多 100 个。');
    }

    private function notFound(Request $request): Response
    {
        return $this->problem($request, 404, 'media-not-found', 'Media Not Found', '图片不存在或已经被删除。');
    }

    private function unavailable(Request $request): Response
    {
        return $this->problem($request, 503, 'service-unavailable', 'Service Unavailable', '媒体服务暂时不可用。');
    }

    private function problem(Request $request, int $status, string $type, string $title, string $detail): Response
    {
        return Response::problem([
            'type' => '/problems/' . $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->path(),
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
