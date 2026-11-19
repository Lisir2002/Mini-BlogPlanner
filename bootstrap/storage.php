<?php

declare(strict_types=1);

use Core\Config\Env;

/** 解析共享存储根；默认是应用根下的 storage。 */
function cream_storage_path(string $root, string $suffix = ''): string
{
    $base = rtrim(Env::get('STORAGE_DIR', $root . '/storage') ?? $root . '/storage', '/');

    return $suffix === '' ? $base : $base . '/' . ltrim($suffix, '/');
}
