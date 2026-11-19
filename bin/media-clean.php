<?php

declare(strict_types=1);

use Core\Config\Env;
use Core\Content\MediaRepository;
use Core\Database\Connection;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
Env::load($root . '/.env');

$json = in_array('--json', $argv, true);
$confirm = in_array('--confirm', $argv, true);
$ageHours = 24;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--age-hours=')) {
        $ageHours = max(1, (int) substr($argument, strlen('--age-hours=')));
    }
}

try {
    $repo = new MediaRepository(Connection::fromEnv());
    $known = array_fill_keys($repo->diskNames(), true);
    $storage = rtrim(Env::get('STORAGE_DIR', $root . '/storage') ?? $root . '/storage', '/');
    $uploads = $storage . '/uploads';
    $now = time();
    $orphans = [];
    $deleted = 0;

    if (is_dir($uploads)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace($uploads . '/', '', $path);
            if (isset($known[$relative])) {
                continue;
            }
            if ($file->getMTime() > $now - $ageHours * 3600) {
                continue;
            }
            $orphans[] = $relative;
            if ($confirm) {
                @unlink($path);
                $deleted++;
            }
        }
    }

    $result = [
        'ok' => true,
        'data' => [
            'dry_run' => !$confirm,
            'orphans' => count($orphans),
            'deleted' => $deleted,
            'files' => array_values($orphans),
        ],
    ];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo sprintf("%s: %d orphan(s), %d deleted\n", $confirm ? 'Cleaned' : 'Dry run', count($orphans), $deleted);
        foreach ($orphans as $orphan) {
            echo '  ' . $orphan . PHP_EOL;
        }
    }
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => 5, 'message' => $exception->getMessage()];
    if ($json) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(5);
}
