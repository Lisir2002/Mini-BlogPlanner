<?php

declare(strict_types=1);

use Core\Upgrade\ReleaseManifest;
use Core\Version\Version;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

$json = in_array('--json', $argv, true);
$command = $argv[1] ?? 'help';

$arg = static function (string $name, ?string $default = null) use ($argv): ?string {
    foreach ($argv as $value) {
        if (str_starts_with($value, '--' . $name . '=')) {
            return substr($value, strlen($name) + 3);
        }
    }

    return $default;
};
$emit = static function (array $payload, int $code) use ($json): never {
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif (($payload['ok'] ?? false) !== true) {
        fwrite(STDERR, (string) ($payload['message'] ?? 'error') . PHP_EOL);
    } else {
        echo (string) ($payload['message'] ?? 'ok') . PHP_EOL;
    }
    exit($code);
};

if ($command === 'help') {
    $emit(['ok' => true, 'message' => implode(PHP_EOL, [
        'Usage: php bin/release.php build --id=v0.6.0 --out=/path/to/release [--with-vendor] [--json]',
        '       php bin/release.php verify --dir=/path/to/release [--json]',
    ])], 0);
}

if ($command === 'verify') {
    $directory = $arg('dir');
    if ($directory === null) {
        $emit(['ok' => false, 'message' => '--dir is required.'], 2);
    }
    try {
        $manifest = ReleaseManifest::fromDirectory($directory);
        $manifest->verifyFiles();
    } catch (Throwable $exception) {
        $emit(['ok' => false, 'message' => $exception->getMessage()], 1);
    }
    $emit(['ok' => true, 'message' => sprintf('Release %s is valid.', $manifest->releaseId), 'data' => [
        'release_id' => $manifest->releaseId,
        'app' => $manifest->app,
        'schema' => $manifest->schema,
        'files' => count($manifest->files),
    ]], 0);
}

if ($command !== 'build') {
    $emit(['ok' => false, 'message' => 'Unknown command: ' . $command], 2);
}

$id = $arg('id');
$out = $arg('out');
if ($id === null || $out === null) {
    $emit(['ok' => false, 'message' => '--id and --out are required.'], 2);
}
if (preg_match('/^[A-Za-z0-9._-]{1,80}$/', $id) !== 1) {
    $emit(['ok' => false, 'message' => 'Release id contains unsafe characters.'], 2);
}

$version = Version::fromFile($root . '/version.json');
if ($version->app() !== $id && 'v' . $version->app() !== $id) {
    $emit(['ok' => false, 'message' => 'Release id must equal version.json app or v<app>.'], 2);
}

$out = rtrim($out, '/');
if (file_exists($out) && (!is_dir($out) || (scandir($out) ?: []) !== ['.', '..'])) {
    $emit(['ok' => false, 'message' => 'Output directory must be absent or empty.'], 2);
}
if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    $emit(['ok' => false, 'message' => 'Unable to create output directory.'], 1);
}

$skipTop = ['.git', '.env', 'storage', '.phpstan.cache', '.phpunit.result.cache', 'releases', 'current', 'previous', 'node_modules'];
$withVendor = in_array('--with-vendor', $argv, true);
if (!$withVendor) {
    $skipTop[] = 'vendor';
}
$files = [];
$outReal = realpath($out) ?: $out;

$walk = static function (string $source, string $relative = '') use (&$walk, &$files, $root, $outReal, $skipTop): void {
    $entries = scandir($source);
    if ($entries === false) {
        throw new RuntimeException('Unable to read directory: ' . $source);
    }
    sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $source . '/' . $entry;
        $rel = $relative === '' ? $entry : $relative . '/' . $entry;
        if ($relative === '' && in_array($entry, $skipTop, true)) {
            continue;
        }
        if ($path === $outReal || str_starts_with($path, $outReal . '/')) {
            continue;
        }
        if (is_link($path)) {
            continue;
        }
        if (is_dir($path)) {
            $walk($path, $rel);
            continue;
        }
        if (!is_file($path)) {
            continue;
        }
        $target = $outReal . '/' . $rel;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create release directory: ' . $parent);
        }
        if (!copy($path, $target)) {
            throw new RuntimeException('Unable to copy file: ' . $rel);
        }
        chmod($target, fileperms($path) & 0777);
        $hash = hash_file('sha256', $target);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash file: ' . $rel);
        }
        $files[$rel] = $hash;
    }
};

try {
    $walk($root);
} catch (Throwable $exception) {
    $emit(['ok' => false, 'message' => $exception->getMessage()], 1);
}

// Browsers receive .gz through gzip_static; always rebuild it from the copied plain file.
$generated = [];
foreach (array_keys($files) as $rel) {
    if (preg_match('#^public/assets/.+\.(?:css|js)$#', $rel) !== 1) {
        continue;
    }
    $target = $out . '/' . $rel;
    $payload = file_get_contents($target);
    $compressed = is_string($payload) ? gzencode($payload, 9) : false;
    if ($compressed === false || file_put_contents($target . '.gz', $compressed, LOCK_EX) === false) {
        $emit(['ok' => false, 'message' => 'Unable to compress asset: ' . $rel], 1);
    }
    $generated[$rel . '.gz'] = true;
}
foreach (array_keys($generated) as $rel) {
    $hash = hash_file('sha256', $out . '/' . $rel);
    if (!is_string($hash)) {
        $emit(['ok' => false, 'message' => 'Unable to hash compressed asset: ' . $rel], 1);
    }
    $files[$rel] = $hash;
}

$commit = $arg('commit', getenv('RELEASE_COMMIT') ?: 'unknown') ?? 'unknown';
$artifact = hash('sha256', implode("\n", array_map(
    static fn (string $path, string $hash): string => $path . ':' . $hash,
    array_keys($files),
    array_values($files),
)));
$manifest = [
    'release_id' => $id,
    'app' => $version->app(),
    'channel' => $version->channel(),
    'schema' => $version->schema(),
    'api' => $version->api(),
    'commit' => $commit,
    'built_at' => gmdate('c'),
    'artifact_sha256' => $artifact,
    'files_sha256' => $files,
];
$manifestPath = $out . '/RELEASE_MANIFEST.json';
$temporary = $manifestPath . '.tmp.' . bin2hex(random_bytes(4));
file_put_contents($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
chmod($temporary, 0640);
rename($temporary, $manifestPath);

$emit(['ok' => true, 'message' => sprintf('Release %s built with %d files.', $id, count($files)), 'data' => [
    'release_id' => $id,
    'app' => $version->app(),
    'schema' => $version->schema(),
    'files' => count($files),
    'out' => $out,
    'artifact_sha256' => $artifact,
]], 0);
