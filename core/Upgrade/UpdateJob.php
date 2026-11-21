<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

/**
 * 一键更新任务的持久化状态。root 助手写入，后台页面只读并使用短轮询展示。
 */
final class UpdateJob
{
    public const STATUSES = ['idle', 'queued', 'running', 'done', 'failed'];

    public function __construct(private readonly string $directory) {}

    /** @return array<string, mixed>|null */
    public function load(): ?array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $job */
    public function save(array $job): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('无法创建更新状态目录。');
        }

        $job['updated_at'] = gmdate('c');
        $file = $this->file();
        $temporary = $file . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('无法写入更新任务状态。');
        }
        chmod($temporary, 0644);
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('无法替换更新任务状态。');
        }
        @chmod($file, 0644);
    }

    /** @param array<string, mixed> $job
     *  @return array<string, mixed>
     */
    public function normalize(array $job, int $staleSeconds = 900): array
    {
        $status = (string) ($job['status'] ?? 'idle');
        if (!in_array($status, self::STATUSES, true)) {
            $job['status'] = 'failed';
            $job['error'] = '更新任务状态无法识别。';

            return $job;
        }

        if (in_array($status, ['queued', 'running'], true)
            && $this->isStale($job, $status === 'queued' ? 90 : $staleSeconds)
        ) {
            $job['status'] = 'failed';
            $job['error'] = $status === 'queued'
                ? '更新任务没有启动，请检查更新助手配置。'
                : '更新任务长时间没有进展，可能已被系统中断。';
            $job['stale'] = true;
        }

        return $job;
    }

    /** @param array<string, mixed>|null $job */
    public function active(?array $job, int $staleSeconds = 900): bool
    {
        if ($job === null) {
            return false;
        }

        return in_array((string) ($job['status'] ?? ''), ['queued', 'running'], true)
            && !$this->isStale($job, (string) ($job['status'] ?? '') === 'queued' ? 90 : $staleSeconds);
    }

    public function appendLog(string $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $line = '[' . gmdate('c') . '] ' . trim($message) . PHP_EOL;
        file_put_contents($this->directory . '/helper.log', $line, FILE_APPEND | LOCK_EX);
        @chmod($this->directory . '/helper.log', 0644);
    }

    private function file(): string
    {
        return rtrim($this->directory, '/') . '/job.json';
    }

    /** @param array<string, mixed> $job */
    private function isStale(array $job, int $seconds): bool
    {
        $updated = (string) ($job['updated_at'] ?? '');
        if ($updated === '') {
            return true;
        }
        $timestamp = strtotime($updated);
        if ($timestamp === false) {
            return true;
        }

        return time() - $timestamp > $seconds;
    }
}
