<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\MigrationLoader;
use Core\Version\Version;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

$version = Version::fromFile($root . '/version.json');
$loader = new MigrationLoader();
$all = [];

$sources = [['core', $root . '/database/migrations']];
foreach (glob($root . '/plugins/*/migrations', GLOB_ONLYDIR) ?: [] as $directory) {
    $sources[] = ['plugin:' . basename(dirname($directory)), $directory];
}
foreach (glob($root . '/themes/*/migrations', GLOB_ONLYDIR) ?: [] as $directory) {
    $sources[] = ['theme:' . basename(dirname($directory)), $directory];
}

try {
    foreach ($sources as [$scope, $directory]) {
        foreach ($loader->load($directory, $scope) as $migration) {
            $file = basename($migration->file);
            if (!str_starts_with($file, (string) $migration->version . '_')) {
                throw new RuntimeException(sprintf('Migration filename must start with its version: %s', $file));
            }
            if (!preg_match('/^[a-f0-9]{64}$/', $migration->checksum)) {
                throw new RuntimeException(sprintf('Migration checksum is invalid: %s', $file));
            }
            $all[$migration->identifier()] = $migration;
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$coreVersions = array_values(array_filter(
    $all,
    static fn (Migration $migration): bool => $migration->scope === 'core',
));
$maxCore = $coreVersions === [] ? 0 : max(array_map(static fn (Migration $m): int => $m->version, $coreVersions));

if ($maxCore !== $version->schema()) {
    fwrite(STDERR, sprintf(
        "FAIL highest core migration %d does not match version.json schema %d.\n",
        $maxCore,
        $version->schema(),
    ));
    exit(1);
}

foreach ($all as $migration) {
    echo sprintf(
        "OK %-18s %-32s reversible=%s irreversible=%s\n",
        $migration->identifier(),
        $migration->name,
        $migration->reversible ? 'yes' : 'no',
        $migration->irreversible ? 'yes' : 'no',
    );
}
