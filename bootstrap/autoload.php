<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$vendor = $root . '/vendor/autoload.php';

if (is_file($vendor)) {
    require $vendor;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Core\\' => $root . '/core/',
        'Install\\' => $root . '/plugins/install/src/',
    ];

    if (str_starts_with($class, 'Plugin\\')) {
        $parts = explode('\\', substr($class, 7));
        $plugin = strtolower((string) array_shift($parts));
        $file = $root . '/plugins/' . $plugin . '/src/' . implode('/', $parts) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }

    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $base . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
