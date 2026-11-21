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
        $file = dirname(__DIR__, 2) . '/public/assets/' . ltrim((string) $safe, '/');
        $mtime = is_file($file) ? filemtime($file) : false;
        $stamp = $mtime === false ? '' : (string) $mtime;
        $query = 'v=' . rawurlencode($version);
        if ($stamp !== '') {
            $query .= '&m=' . rawurlencode($stamp);
        }

        return $url . '?' . $query;
    }
}

if (!function_exists('cream_site_url')) {
    function cream_site_url(): string
    {
        $url = trim((string) \Core\Config\Env::get('APP_URL', ''));

        return $url === '' ? '' : rtrim($url, '/');
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

if (!function_exists('cream_update_notes')) {
    function cream_update_notes(string $body): string
    {
        $lines = preg_split('/\R/u', str_replace("\r\n", "\n", $body));
        if (!is_array($lines)) {
            return '';
        }
        $html = [];
        $list = false;
        foreach ($lines as $line) {
            $line = rtrim((string) $line);
            if ($line === '') {
                if ($list) {
                    $html[] = '</ul>';
                    $list = false;
                }
                continue;
            }
            if (preg_match('/^#{1,3}\s+(.+)$/u', $line, $matches) === 1) {
                if ($list) {
                    $html[] = '</ul>';
                    $list = false;
                }
                $html[] = '<h3 class="update-notes-heading">' . cream_update_inline($matches[1]) . '</h3>';
                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/u', $line, $matches) === 1) {
                if (!$list) {
                    $html[] = '<ul class="update-notes-list">';
                    $list = true;
                }
                $html[] = '<li>' . cream_update_inline($matches[1]) . '</li>';
                continue;
            }
            if ($list) {
                $html[] = '</ul>';
                $list = false;
            }
            $html[] = '<p>' . cream_update_inline($line) . '</p>';
        }
        if ($list) {
            $html[] = '</ul>';
        }

        return implode('', $html);
    }
}

if (!function_exists('cream_update_inline')) {
    function cream_update_inline(string $text): string
    {
        $escaped = cream_e($text);
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);

        return is_string($html) ? $html : $escaped;
    }
}

if (!function_exists('cream_word_count')) {
    function cream_word_count(string $body): int
    {
        $text = preg_replace('/<[^>]+>/u', ' ', $body);
        $text = is_string($text) ? html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (!is_string($text) || $text === '') {
            return 0;
        }
        $cjk = preg_match_all('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}]/u', $text);
        $words = preg_match_all('/[A-Za-z0-9]+(?:[\x{2019}\'-][A-Za-z0-9]+)*/u', $text);

        return (int) $cjk + (int) $words;
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
