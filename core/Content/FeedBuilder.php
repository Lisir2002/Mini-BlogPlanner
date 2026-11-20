<?php

declare(strict_types=1);

namespace Core\Content;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class FeedBuilder
{
    /**
     * @param list<Post> $posts
     * @param array{title:string,description:string,language:string,timezone?:string} $site
     */
    public static function render(array $posts, array $site, string $baseUrl, string $appVersion = ''): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $title = $site['title'];
        $description = $site['description'];
        $language = $site['language'];
        $timezone = new DateTimeZone($site['timezone'] ?? 'UTC');
        $selfUrl = $baseUrl . '/feed.xml';
        $updatedAt = null;

        $items = '';
        foreach ($posts as $post) {
            $url = $baseUrl . '/posts/' . rawurlencode($post->slug) . '.html';
            $published = self::date($post->publishedAt, $timezone);
            $updatedAt = $updatedAt === null || $post->updatedAt > $updatedAt ? $post->updatedAt : $updatedAt;

            $items .= "    <item>\n"
                . '      <title>' . self::escape($post->title) . "</title>\n"
                . '      <link>' . self::escape($url) . "</link>\n"
                . '      <guid isPermaLink="true">' . self::escape($url) . "</guid>\n"
                . '      <pubDate>' . self::escape($published) . "</pubDate>\n"
                . '      <description>' . self::escape($post->excerpt) . "</description>\n";

            foreach ($post->tags as $tag) {
                $items .= '      <category>' . self::escape($tag) . "</category>\n";
            }

            $items .= "    </item>\n";
        }

        $lastBuild = self::date($updatedAt ?? 'now', $timezone);
        $generator = 'Mini-BlogPlanner' . ($appVersion === '' ? '' : ' ' . $appVersion);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . self::escape($title) . "</title>\n"
            . '    <link>' . self::escape($baseUrl . '/') . "</link>\n"
            . '    <description>' . self::escape($description) . "</description>\n"
            . '    <language>' . self::escape($language) . "</language>\n"
            . '    <lastBuildDate>' . self::escape($lastBuild) . "</lastBuildDate>\n"
            . '    <generator>' . self::escape($generator) . "</generator>\n"
            . '    <ttl>300</ttl>' . "\n"
            . '    <atom:link href="' . self::escape($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . $items
            . "  </channel>\n"
            . "</rss>\n";
    }

    private static function date(string $value, DateTimeZone $timezone): string
    {
        try {
            return (new DateTimeImmutable($value, $timezone))->format(DATE_RSS);
        } catch (Throwable) {
            return gmdate(DATE_RSS);
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
