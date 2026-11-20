<?php

declare(strict_types=1);

namespace Core\Seo;

final class SitemapBuilder
{
    /**
     * @param list<array{loc:string,lastmod?:string,changefreq?:string,priority?:float}> $entries
     */
    public static function render(array $entries): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($entries as $entry) {
            $location = trim($entry['loc']);
            if ($location === '') {
                continue;
            }

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . self::escape($location) . '</loc>';

            $lastModified = trim((string) ($entry['lastmod'] ?? ''));
            if ($lastModified !== '') {
                $lines[] = '    <lastmod>' . self::escape($lastModified) . '</lastmod>';
            }

            $changeFrequency = trim((string) ($entry['changefreq'] ?? ''));
            if ($changeFrequency !== '') {
                $lines[] = '    <changefreq>' . self::escape($changeFrequency) . '</changefreq>';
            }

            if (isset($entry['priority'])) {
                $priority = max(0.0, min(1.0, (float) $entry['priority']));
                $lines[] = '    <priority>' . number_format($priority, 1, '.', '') . '</priority>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
