<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = $root . '/core';
$violations = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($core, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $relative = str_replace($root . '/', '', $path);
    $source = (string) file_get_contents($path);

    if (!str_ends_with($relative, 'ResponseEmitter.php')
        && preg_match('/\b(echo|print|var_dump|print_r)\b/', $source) === 1
    ) {
        $violations[] = $relative . ': output statement';
    }
    if (str_contains($source, '<?=') || str_contains($source, '<html')) {
        $violations[] = $relative . ': HTML in core';
    }
    if (!str_ends_with($relative, 'ResponseEmitter.php')) {
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (preg_match('/\bheader\s*\(/', $line) === 1
                && !str_contains($line, 'function header')
                && !str_contains($line, '->header')
                && !str_contains($line, 'withHeader')
            ) {
                $violations[] = $relative . ': header() outside ResponseEmitter';
                break;
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "FAIL core boundary\n" . implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

echo "OK core boundary: no HTML, no direct output, no header() outside emitter\n";
