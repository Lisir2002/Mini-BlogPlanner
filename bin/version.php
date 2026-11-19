<?php

declare(strict_types=1);

$ctx = require dirname(__DIR__) . '/bootstrap/cli.php';

/** @var Core\Version\Version $version */
$version = $ctx['version'];

$json = in_array('--json', $argv, true);

if ($json) {
    echo json_encode([
        'ok' => true,
        'data' => $version->toArray(),
        'runtime' => [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

echo "Mini-BlogPlanner\n";
echo "  app       : {$version->app()} ({$version->channel()})\n";
echo "  schema    : {$version->schema()} (minimum {$version->schemaMin()})\n";
echo "  api       : {$version->api()}\n";
echo "  runtime   : PHP " . PHP_VERSION . "\n";
echo "  requires  : PHP {$version->php()}, MySQL {$version->mysql()}\n";
