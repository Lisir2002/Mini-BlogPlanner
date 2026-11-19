<?php

declare(strict_types=1);

use Core\Config\Env;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
Env::load($root . '/.env');

$json = in_array('--json', $argv, true);
$confirm = in_array('--confirm', $argv, true);
$backup = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--backup=')) {
        $backup = substr($argument, strlen('--backup='));
    }
}

try {
    if (!$confirm) {
        throw new RuntimeException('Restore requires --confirm.');
    }
    if ($backup === null || $backup === '' || str_contains($backup, '..') || str_contains($backup, '/')) {
        throw new RuntimeException('Use a valid --backup=<backup_id>.');
    }

    $backupRoot = Env::get('BACKUP_DIR', $root . '/storage/backups') ?? '';
    $directory = rtrim($backupRoot, '/') . '/' . $backup;
    $manifestFile = $directory . '/manifest.json';
    if (!is_file($manifestFile) || !is_file($directory . '/db.sql')) {
        throw new RuntimeException('Backup not found or incomplete.');
    }

    $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['backup_id'] ?? null) !== $backup) {
        throw new RuntimeException('Backup manifest is invalid.');
    }

    $checks = $directory . '/checksums.txt';
    if (!is_file($checks)) {
        throw new RuntimeException('Backup checksums are missing.');
    }

    $verify = escapeshellarg('sha256sum') . ' -c ' . escapeshellarg($checks);
    exec('cd ' . escapeshellarg($directory) . ' && ' . $verify . ' >/dev/null 2>&1', $ignored, $verifyCode);
    if ($verifyCode !== 0) {
        throw new RuntimeException('Backup checksum verification failed.');
    }

    $mysql = Env::get('MYSQL_BIN', 'mysql') ?? 'mysql';
    $host = Env::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    $port = Env::get('DB_PORT', '3306') ?? '3306';
    $name = Env::require('DB_NAME');
    $user = Env::require('DB_USER');

    $command = sprintf(
        '%s --host=%s --port=%s --user=%s %s < %s',
        escapeshellarg($mysql),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        escapeshellarg($name),
        escapeshellarg($directory . '/db.sql'),
    );

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['MYSQL_PWD'] = Env::get('DB_PASSWORD', '') ?? '';

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysql restore.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0) {
        throw new RuntimeException('Database restore failed: ' . trim((string) $stderr));
    }

    $result = ['ok' => true, 'data' => ['backup_id' => $backup, 'database' => $name]];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo "Restored {$backup} into {$name}.\n";
    }
    exit(0);
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => 4, 'message' => $exception->getMessage()];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(4);
}
