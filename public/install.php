<?php

declare(strict_types=1);

use Core\Config\Env;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
require $root . '/bootstrap/storage.php';
Env::loadWithFallback($root);

if (is_file(cream_storage_path($root, 'installed.lock'))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not Found');
}

$bootstrap = $root . '/plugins/install/bootstrap.php';
if (!is_file($bootstrap)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Installer is not deployed.');
}

require $bootstrap;
