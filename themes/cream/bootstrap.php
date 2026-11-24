<?php

declare(strict_types=1);

if (!function_exists('cream_e')) {
    function cream_e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('cream_expression_catalog')) {
    /** @return array<string, array<string, mixed>> */
    function cream_expression_catalog(): array
    {
        static $catalog = null;
        if (!is_array($catalog)) {
            $file = __DIR__ . '/catalog.php';
            $loaded = is_file($file) ? require $file : [];
            $catalog = is_array($loaded) ? $loaded : [];
        }

        return $catalog;
    }
}

if (!function_exists('cream_sticker_map')) {
    /** @return array<string, array{slug:string, group:string}> */
    function cream_sticker_map(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }
        $map = [];
        $catalog = cream_expression_catalog();
        foreach ($catalog as $section) {
            if (($section['kind'] ?? '') !== 'sticker') {
                continue;
            }
            foreach (($section['groups'] ?? []) as $group) {
                foreach (($group['items'] ?? []) as $item) {
                    $name = (string) ($item['name'] ?? '');
                    $slug = (string) ($item['slug'] ?? '');
                    if ($name !== '' && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                        $map[$name] = ['slug' => $slug, 'group' => (string) ($group['label'] ?? '')];
                    }
                }
            }
        }

        return $map;
    }
}

if (!function_exists('cream_sticker_picture')) {
    function cream_sticker_picture(string $slug, int $size = 72): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return '';
        }
        $gif = cream_asset('stickers/' . $slug . '.gif');
        $png = cream_asset('stickers/' . $slug . '.png');
        $px = (string) max(24, min(192, $size));

        return '<picture><source srcset="' . cream_e($png) . '" media="(prefers-reduced-motion: reduce)"><img src="' . cream_e($gif) . '" alt="" width="' . $px . '" height="' . $px . '" loading="lazy" decoding="async"></picture>';
    }
}

if (!function_exists('cream_comment_body')) {
    function cream_comment_body(string $body): string
    {
        $escaped = cream_e($body);
        $map = cream_sticker_map();
        $rendered = preg_replace_callback('/[\[［]贴图[：:]([^\[\]\r\n]{1,12})[\]］]/u', static function (array $matches) use ($map): string {
            $name = (string) $matches[1];
            if (!isset($map[$name])) {
                return $matches[0];
            }
            $slug = $map[$name]['slug'];
            $picture = cream_sticker_picture($slug, 72);

            return '<span class="comment-sticker" role="img" aria-label="贴图：' . cream_e($name) . '">' . $picture . '</span>';
        }, $escaped, 6);
        if (!is_string($rendered)) {
            $rendered = $escaped;
        }

        return nl2br($rendered);
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
