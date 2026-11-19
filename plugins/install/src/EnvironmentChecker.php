<?php

declare(strict_types=1);

namespace Install;

use Core\Version\Version;

final class EnvironmentChecker
{
    /** @return array{errors:list<string>,warnings:list<string>,checks:list<array{label:string,ok:bool,detail:string}>} */
    public function check(string $root, ?string $storageRoot = null): array
    {
        $storageRoot = rtrim($storageRoot ?? $root . '/storage', '/');
        $errors = [];
        $warnings = [];
        $checks = [];

        $php = PHP_VERSION;
        $phpOk = version_compare($php, '8.5.0', '>=');
        $checks[] = $this->row('PHP 版本', $phpOk, sprintf('%s（要求 >= 8.5.0）', $php));
        if (!$phpOk) {
            $errors[] = 'PHP 版本低于 8.5.0，请升级 PHP CLI/FPM。';
        }

        foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'curl', 'intl', 'gd'] as $extension) {
            $ok = extension_loaded($extension);
            $checks[] = $this->row('扩展 ' . $extension, $ok, $ok ? '已加载' : '缺失');
            if (!$ok) {
                $errors[] = sprintf('缺少 PHP 扩展：%s。', $extension);
            }
        }

        $memory = $this->iniBytes((string) ini_get('memory_limit'));
        if ($memory < 256 * 1024 * 1024) {
            $warnings[] = 'memory_limit 建议不低于 256M。';
        }
        if ($this->iniBytes((string) ini_get('post_max_size')) < 12 * 1024 * 1024) {
            $warnings[] = 'post_max_size 建议不低于 12M。';
        }
        if ($this->iniBytes((string) ini_get('upload_max_filesize')) < 12 * 1024 * 1024) {
            $warnings[] = 'upload_max_filesize 建议不低于 12M。';
        }
        if ((int) ini_get('max_execution_time') < 60) {
            $warnings[] = 'max_execution_time 建议不低于 60 秒。';
        }

        foreach (['', 'cache', 'logs', 'sessions', 'uploads', 'upgrade'] as $directory) {
            $path = $directory === '' ? $storageRoot : $storageRoot . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                $checks[] = $this->row($directory === '' ? 'storage' : 'storage/' . $directory, false, '无法创建');
                $errors[] = sprintf('目录不可创建：%s。', $path);
                continue;
            }
            $writable = is_writable($path);
            $checks[] = $this->row($directory === '' ? 'storage' : 'storage/' . $directory, $writable, $writable ? '可写' : '不可写');
            if (!$writable) {
                $errors[] = sprintf('目录不可写：%s。请执行 chown -R www-data:www-data %s。', $path, $path);
            }
        }

        $vendor = is_file($root . '/vendor/autoload.php');
        $checks[] = $this->row('Composer 依赖', $vendor, $vendor ? '已安装' : '缺少 vendor/autoload.php');
        if (!$vendor) {
            $errors[] = '缺少 vendor/autoload.php，请先运行 composer install --no-dev。';
        }

        try {
            Version::fromFile($root . '/version.json');
            $checks[] = $this->row('version.json', true, '可解析');
        } catch (\Throwable $exception) {
            $checks[] = $this->row('version.json', false, $exception->getMessage());
            $errors[] = 'version.json 缺失或损坏，安装中止。';
        }

        if (is_file($storageRoot . '/maintenance.flag')) {
            $checks[] = $this->row('维护状态', false, 'maintenance.flag 存在');
            $errors[] = '站点处于维护/升级状态，不能安装。';
        } else {
            $checks[] = $this->row('维护状态', true, '未处于维护');
        }

        $assets = is_file($root . '/public/assets/manifest.json');
        $checks[] = $this->row('前端构建产物', $assets, $assets ? 'manifest 已存在' : '尚未构建');
        if (!$assets) {
            $warnings[] = '尚未检测到 public/assets/manifest.json；安装向导可用，但主题样式需要构建后生效。';
        }

        $free = disk_free_space($root);
        $ok = $free !== false && $free >= 1024 * 1024 * 1024;
        $checks[] = $this->row('磁盘空间', $ok, $free === false ? '无法读取' : sprintf('%.1f GB 可用', $free / 1024 / 1024 / 1024));
        if (!$ok) {
            $errors[] = '可用磁盘空间不足 1GB。';
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'checks' => $checks];
    }

    /** @return array{label:string,ok:bool,detail:string} */
    private function row(string $label, bool $ok, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        if ($unit === 'g') {
            return $number * 1024 * 1024 * 1024;
        }
        if ($unit === 'm') {
            return $number * 1024 * 1024;
        }
        if ($unit === 'k') {
            return $number * 1024;
        }

        return $number;
    }
}
