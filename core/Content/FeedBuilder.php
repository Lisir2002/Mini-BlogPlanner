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
        return self::rss($posts, $site, $baseUrl, $appVersion);
    }

    /**
     * @param list<Post> $posts
     * @param array{title:string,description:string,language:string,timezone?:string} $site
     */
    public static function rss(array $posts, array $site, string $baseUrl, string $appVersion = ''): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $timezone = new DateTimeZone($site['timezone'] ?? 'UTC');
        $updatedAt = null;
        $items = '';

        foreach ($posts as $post) {
            $url = $baseUrl . '/posts/' . rawurlencode($post->slug) . '.html';
            $published = self::rssDate($post->publishedAt, $timezone);
            $modifiedAt = $post->updatedAt !== '' ? $post->updatedAt : $post->publishedAt;
            $updatedAt = $updatedAt === null || $modifiedAt > $updatedAt ? $modifiedAt : $updatedAt;

            $items .= "    <item>\n"
                . '      <title>' . self::escape($post->title) . "</title>\n"
                . '      <link>' . self::escape($url) . "</link>\n"
                . '      <guid isPermaLink="true">' . self::escape($url) . "</guid>\n"
                . '      <pubDate>' . self::escape($published) . "</pubDate>\n"
                . '      <description>' . self::escape($post->excerpt) . "</description>\n"
                . '      <content:encoded>' . self::cdata($post->body) . "</content:encoded>\n";

            foreach ($post->tags as $tag) {
                $items .= '      <category>' . self::escape($tag) . "</category>\n";
            }

            $items .= "    </item>\n";
        }

        $lastBuild = self::rssDate($updatedAt ?? 'now', $timezone);
        $generator = 'Mini-BlogPlanner' . ($appVersion === '' ? '' : ' ' . $appVersion);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n"
            . "  <channel>\n"
            . '    <title>' . self::escape($site['title']) . "</title>\n"
            . '    <link>' . self::escape($baseUrl . '/') . "</link>\n"
            . '    <description>' . self::escape($site['description']) . "</description>\n"
            . '    <language>' . self::escape($site['language']) . "</language>\n"
            . '    <lastBuildDate>' . self::escape($lastBuild) . "</lastBuildDate>\n"
            . '    <generator>' . self::escape($generator) . "</generator>\n"
            . '    <ttl>300</ttl>' . "\n"
            . '    <atom:link href="' . self::escape($baseUrl . '/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n"
            . $items
            . "  </channel>\n"
            . "</rss>\n";
    }

    /**
     * @param list<Post> $posts
     * @param array{title:string,description:string,language:string,timezone?:string} $site
     */
    public static function atom(array $posts, array $site, string $baseUrl, string $appVersion = ''): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $timezone = new DateTimeZone($site['timezone'] ?? 'UTC');
        $updatedAt = null;
        $entries = '';

        foreach ($posts as $post) {
            $url = $baseUrl . '/posts/' . rawurlencode($post->slug) . '.html';
            $published = self::isoDate($post->publishedAt, $timezone);
            $modifiedRaw = $post->updatedAt !== '' ? $post->updatedAt : $post->publishedAt;
            $modified = self::isoDate($modifiedRaw, $timezone);
            $updatedAt = $updatedAt === null || $modifiedRaw > $updatedAt ? $modifiedRaw : $updatedAt;

            $entries .= "  <entry>\n"
                . '    <title type="text">' . self::escape($post->title) . "</title>\n"
                . '    <link rel="alternate" type="text/html" href="' . self::escape($url) . '"/>' . "\n"
                . '    <id>' . self::escape($url) . "</id>\n"
                . '    <published>' . self::escape($published) . "</published>\n"
                . '    <updated>' . self::escape($modified) . "</updated>\n"
                . '    <summary type="text">' . self::escape($post->excerpt) . "</summary>\n"
                . '    <content type="html">' . self::escape($post->body) . "</content>\n";

            foreach ($post->tags as $tag) {
                $entries .= '    <category term="' . self::escape($tag) . '"/>' . "\n";
            }

            $entries .= "  </entry>\n";
        }

        $generator = 'Mini-BlogPlanner' . ($appVersion === '' ? '' : ' ' . $appVersion);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="' . self::escape($site['language']) . '">' . "\n"
            . '  <title type="text">' . self::escape($site['title']) . "</title>\n"
            . '  <subtitle type="text">' . self::escape($site['description']) . "</subtitle>\n"
            . '  <id>' . self::escape($baseUrl . '/') . "</id>\n"
            . '  <updated>' . self::escape(self::isoDate($updatedAt ?? 'now', $timezone)) . "</updated>\n"
            . '  <link rel="alternate" type="text/html" href="' . self::escape($baseUrl . '/') . '"/>' . "\n"
            . '  <link rel="self" type="application/atom+xml" href="' . self::escape($baseUrl . '/atom.xml') . '"/>' . "\n"
            . '  <author><name>' . self::escape($site['title']) . '</name><uri>' . self::escape($baseUrl . '/') . "</uri></author>\n"
            . '  <generator uri="' . self::escape($baseUrl . '/') . '" version="' . self::escape($appVersion) . '">' . self::escape($generator) . "</generator>\n"
            . $entries
            . "</feed>\n";
    }

    /**
     * @param list<Post> $posts
     * @param array{title:string,description:string,language:string,timezone?:string} $site
     */
    public static function json(array $posts, array $site, string $baseUrl, string $appVersion = ''): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $timezone = new DateTimeZone($site['timezone'] ?? 'UTC');
        $items = [];

        foreach ($posts as $post) {
            $url = $baseUrl . '/posts/' . rawurlencode($post->slug) . '.html';
            $items[] = [
                'id' => $url,
                'url' => $url,
                'title' => $post->title,
                'content_html' => $post->body,
                'summary' => $post->excerpt,
                'date_published' => self::isoDate($post->publishedAt, $timezone),
                'date_modified' => self::isoDate(
                    $post->updatedAt !== '' ? $post->updatedAt : $post->publishedAt,
                    $timezone,
                ),
                'tags' => $post->tags,
            ];
        }

        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $site['title'],
            'home_page_url' => $baseUrl . '/',
            'feed_url' => $baseUrl . '/feed.json',
            'description' => $site['description'],
            'language' => $site['language'],
            'authors' => [
                ['name' => $site['title'], 'url' => $baseUrl . '/'],
            ],
            'items' => $items,
        ];

        if ($appVersion !== '') {
            $feed['_generator'] = 'Mini-BlogPlanner ' . $appVersion;
        }

        return (string) json_encode(
            $feed,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private static function rssDate(string $value, DateTimeZone $timezone): string
    {
        try {
            return (new DateTimeImmutable($value, $timezone))->format(DATE_RSS);
        } catch (Throwable) {
            return gmdate(DATE_RSS);
        }
    }

    private static function isoDate(string $value, DateTimeZone $timezone): string
    {
        try {
            return (new DateTimeImmutable($value, $timezone))->format(DATE_ATOM);
        } catch (Throwable) {
            return gmdate(DATE_ATOM);
        }
    }

    private static function cdata(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    private static function escape(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
    }
}
