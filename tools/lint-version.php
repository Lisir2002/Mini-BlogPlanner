<?php

declare(strict_types=1);

use Core\Version\Version;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

$version = Version::fromFile($root . '/version.json');
$data = $version->toArray();
$tag = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--tag=')) {
        $tag = substr($argument, strlen('--tag='));
    }
}

if (!preg_match('/^\d{10}$/', (string) $version->schema())) {
    fwrite(STDERR, "FAIL schema must use YYYYMMDDNN.\n");
    exit(1);
}
if (!preg_match('/^\d{10}$/', (string) $version->schemaMin())) {
    fwrite(STDERR, "FAIL schema_min must use YYYYMMDDNN.\n");
    exit(1);
}
if ($version->schemaMin() > $version->schema()) {
    fwrite(STDERR, "FAIL schema_min > schema.\n");
    exit(1);
}
if ($tag !== null && $tag !== 'v' . $version->app()) {
    fwrite(STDERR, sprintf("FAIL tag %s does not match app %s (expected v%s).\n", $tag, $version->app(), $version->app()));
    exit(1);
}

echo sprintf(
    "OK version app=%s schema=%d schema_min=%d api=%s channel=%s\n",
    $version->app(),
    $version->schema(),
    $version->schemaMin(),
    $version->api(),
    $version->channel(),
);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
