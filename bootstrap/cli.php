<?php

declare(strict_types=1);

use Core\Config\Env;
use Core\Version\Version;

$root = dirname(__DIR__);

require $root . '/bootstrap/autoload.php';
Env::loadWithFallback($root);

return [
    'root' => $root,
    'version_file' => $root . '/version.json',
    'version' => Version::fromFile($root . '/version.json'),
    'storage' => rtrim(Env::get('STORAGE_DIR', $root . '/storage') ?? $root . '/storage', '/'),
];
