<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;
use ZipArchive;

/**
 * 发布包的只读检查与安全解压。只接受普通文件，拒绝路径穿越、
 * 绝对路径、符号链接和体积异常的压缩包。
 */
final class ReleaseArchive
{
    private const MAX_FILES = 3000;
    private const MAX_TOTAL_BYTES = 209715200;
    private const MAX_FILE_BYTES = 52428800;

    public function __construct(private readonly string $path) {}

    /** @return array{files:int,bytes:int} */
    public function inspect(): array
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('无法打开发布压缩包。');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('发布压缩包文件数量异常。');
            }

            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new RuntimeException('发布压缩包条目无法读取。');
                }
                $this->assertEntry($stat);
                $size = (int) $stat['size'];
                if ($size > self::MAX_FILE_BYTES) {
                    throw new RuntimeException('发布压缩包包含过大的文件。');
                }
                $bytes += $size;
                if ($bytes > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('发布压缩包解压体积异常。');
                }
            }

            return ['files' => $zip->numFiles, 'bytes' => $bytes];
        } finally {
            $zip->close();
        }
    }

    public function extractTo(string $directory): void
    {
        $this->inspect();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建发布解压目录。');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('无法打开发布压缩包。');
        }

        try {
            if (!$zip->extractTo($directory)) {
                throw new RuntimeException('发布压缩包解压失败。');
            }
        } finally {
            $zip->close();
        }

        $this->assertNoLinks($directory);
    }

    /** @param array<string, mixed> $stat */
    private function assertEntry(array $stat): void
    {
        $name = (string) ($stat['name'] ?? '');
        if ($name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || str_starts_with($name, '__MACOSX/')
        ) {
            throw new RuntimeException('发布压缩包包含不安全路径。');
        }

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('发布压缩包包含路径穿越条目。');
            }
        }

        $mode = ((int) ($stat['external'] ?? 0)) >> 16;
        if (($mode & 0170000) === 0120000) {
            throw new RuntimeException('发布压缩包包含符号链接。');
        }
    }

    private function assertNoLinks(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('发布目录包含符号链接。');
            }
        }
    }
}
