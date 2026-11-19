<?php

declare(strict_types=1);

if (!function_exists('cream_e')) {
    function cream_e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('cream_asset_version')) {
    function cream_asset_version(): string
    {
        static $version = null;
        if (is_string($version)) {
            return $version;
        }
        $file = dirname(__DIR__, 2) . '/version.json';
        $raw = is_file($file) ? file_get_contents($file) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        $version = is_array($data) && isset($data['app']) ? (string) $data['app'] : '';

        return $version;
    }
}

if (!function_exists('cream_asset')) {
    function cream_asset(string $path): string
    {
        $safe = preg_replace('#[^a-z0-9._/-]#i', '', $path);
        $safe = is_string($safe) ? str_replace('..', '', $safe) : '';
        $url = '/assets/' . ltrim((string) $safe, '/');
        $version = cream_asset_version();

        return $version === '' ? $url : $url . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('cream_icon')) {
    function cream_icon(string $name, string $class = ''): string
    {
        $safe = preg_match('/^[a-z0-9-]+$/', $name) === 1 ? $name : 'dot';
        $class = htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<svg class="icon ' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="'
            . cream_asset('icons.svg') . '#i-' . $safe . '"></use></svg>';
    }
}

if (!function_exists('cream_date')) {
    function cream_date(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->format('Y.m.d');
        } catch (Throwable) {
            return $value;
        }
    }
}
