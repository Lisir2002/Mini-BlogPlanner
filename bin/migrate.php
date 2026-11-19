<?php

declare(strict_types=1);

use Core\Config\Env;
use Core\Database\Connection;
use Core\Database\MigrationLoader;
use Core\Database\Migrator;
use Core\Version\Version;

$ctx = require dirname(__DIR__) . '/bootstrap/cli.php';

/** @var Version $version */
$version = $ctx['version'];
$json = in_array('--json', $argv, true);
$downTo = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--down-to=')) {
        $downTo = (int) substr($argument, strlen('--down-to='));
    }
}

try {
    $db = Connection::fromEnv();
    $migrator = new Migrator($db, $version->app());
    $loader = new MigrationLoader();
    $migrations = $loader->load($ctx['root'] . '/database/migrations', 'core');

    if ($downTo !== null) {
        if (!in_array('--confirm', $argv, true)) {
            throw new RuntimeException('Rolling migrations down requires --confirm.');
        }
        $count = $migrator->downTo($migrations, $downTo);
    } else {
        $count = $migrator->apply($migrations);
    }

    $schema = $migrator->schemaVersion();
    $result = [
        'ok' => true,
        'data' => [
            'direction' => $downTo === null ? 'up' : 'down',
            'migrations' => $count,
            'schema' => $schema,
            'app' => $version->app(),
        ],
    ];

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo sprintf(
            "%s: %d migration(s), schema=%s\n",
            $downTo === null ? 'Applied' : 'Reverted',
            $count,
            $schema === null ? 'missing' : (string) $schema,
        );
    }
    exit(0);
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'code' => 5,
        'message' => $exception->getMessage(),
        'data' => ['exception' => $exception::class],
    ];

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        if (Env::bool('APP_DEBUG')) {
            fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
        }
    }
    exit(5);
}
