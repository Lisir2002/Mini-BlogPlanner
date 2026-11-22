<?php

declare(strict_types=1);

namespace Core\Content;

use finfo;

/**
 * 媒体上传的唯一入口。
 *
 * 后台表单和受令牌保护的 API 共用这里的大小、类型、尺寸校验，
 * 避免两条上传路径对同一张图片给出不同结论。
 */
final class MediaUploadService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_BATCH = 5;

    /** @var array<string, string> */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly MediaRepository $repository,
        private readonly string $storageDir,
    ) {}

    /**
     * @param array<string, mixed> $files 单个 $_FILES 结构，允许 name 为数组的批量结构
     * @return array{stored:list<Media>,errors:list<string>,fatal:bool}
     */
    public function storeBatch(array $files, ?int $uploaderId): array
    {
        $items = $this->normalize($files);
        $stored = [];
        $errors = [];
        $fatal = false;

        foreach (array_slice($items, 0, self::MAX_BATCH) as $file) {
            try {
                $stored[] = $this->store($file, $uploaderId);
            } catch (MediaUploadException $exception) {
                $errors[] = $exception->getMessage();
                $fatal = $fatal || $exception->isStorageFailure();
            } catch (\Throwable) {
                $errors[] = '保存图片失败，请稍后重试。';
                $fatal = true;
            }
        }
        if (count($items) > self::MAX_BATCH) {
            $errors[] = '一次最多上传 ' . self::MAX_BATCH . ' 张图片。';
        }

        return ['stored' => $stored, 'errors' => $errors, 'fatal' => $fatal];
    }

    /** @param array<string, mixed> $file */
    public function store(array $file, ?int $uploaderId): Media
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new MediaUploadException('too_large', '图片不能超过 5MB。');
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '') {
            throw new MediaUploadException('invalid', '上传失败，请重试。');
        }
        if (!is_uploaded_file($tmp)) {
            throw new MediaUploadException('invalid', '上传来源无效。');
        }

        $size = filesize($tmp);
        if ($size === false || $size <= 0) {
            throw new MediaUploadException('invalid', '图片文件为空。');
        }
        if ($size > self::MAX_BYTES) {
            throw new MediaUploadException('too_large', '图片不能超过 5MB。');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new MediaUploadException('unsupported', '只支持 JPEG、PNG、WebP、GIF 图片。');
        }

        $info = getimagesize($tmp);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) {
            throw new MediaUploadException('unsupported', '图片尺寸无效。');
        }

        $extension = self::TYPES[$mime];
        $relativeDirectory = date('Y/m');
        $directory = rtrim($this->storageDir, '/') . '/uploads/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new MediaUploadException('storage', '上传目录不可写。');
        }

        $diskName = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = rtrim($this->storageDir, '/') . '/uploads/' . $diskName;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new MediaUploadException('storage', '保存上传文件失败。');
        }
        @chmod($destination, 0640);

        $original = $this->cleanName((string) ($file['name'] ?? 'image'));
        try {
            $id = $this->repository->create(
                $diskName,
                $original,
                $mime,
                $size,
                (int) $info[0],
                (int) $info[1],
                $uploaderId,
            );
            if ($id === 0) {
                throw new MediaUploadException('database', '保存图片记录失败。');
            }
            $media = $this->repository->find($id);
        } catch (MediaUploadException $exception) {
            @unlink($destination);
            throw $exception;
        } catch (\Throwable $exception) {
            @unlink($destination);
            throw new MediaUploadException('database', '保存图片记录失败。');
        }
        if ($media === null) {
            @unlink($destination);
            throw new MediaUploadException('database', '保存图片记录失败。');
        }

        return $media;
    }

    /** @param array<string, mixed> $files
     *  @return list<array<string, mixed>> */
    private function normalize(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return [$files];
        }
        $items = [];
        foreach (array_keys($files['name']) as $index) {
            $items[] = [
                'name' => $files['name'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $items;
    }

    private function cleanName(string $name): string
    {
        $name = trim(basename($name));
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?? 'image';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? 'image');

        return mb_substr($name === '' ? 'image' : $name, 0, 190);
    }
}
