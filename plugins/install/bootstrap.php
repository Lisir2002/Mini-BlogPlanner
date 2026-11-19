<?php

declare(strict_types=1);

use Core\Config\Env;
use Core\Version\Version;

$root = dirname(__DIR__, 2);

require $root . '/bootstrap/autoload.php';
Env::loadWithFallback($root);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    session_name('install_session');
    session_start();
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

set_time_limit(300);

$version = Version::fromFile($root . '/version.json');

$installer = new \Install\Installer($root, $version);
$installer->run();
