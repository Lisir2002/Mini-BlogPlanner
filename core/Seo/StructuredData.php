<?php

declare(strict_types=1);

namespace Core\Seo;

use Core\Content\Post;

final class StructuredData
{
    /** @param array{title:string,description:string,language:string} $site
     *  @return array<string, mixed> */
    public static function website(array $site, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $name = self::siteName($site);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $baseUrl . '/',
            'description' => $site['description'],
            'inLanguage' => $site['language'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $baseUrl . '/?search={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * @param array{title:string,description:string,language:string} $site
     * @return array<string, mixed>
     */
    public static function blogPosting(
        Post $post,
        string $canonical,
        array $site,
        string $description,
        string $publishedAt,
        string $modifiedAt,
    ): array {
        $siteUrl = self::siteRoot($canonical);
        $publisher = [
            '@type' => 'Organization',
            'name' => self::siteName($site),
            'url' => $siteUrl,
        ];

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->title,
            'description' => $description,
            'datePublished' => $publishedAt,
            'dateModified' => $modifiedAt,
            'author' => $publisher,
            'publisher' => $publisher,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonical,
            ],
            'url' => $canonical,
            'inLanguage' => $site['language'],
        ];

        if ($post->tags !== []) {
            $data['keywords'] = implode(', ', $post->tags);
        }

        if ($siteUrl !== '') {
            $data['isPartOf'] = [
                '@type' => 'WebSite',
                'name' => self::siteName($site),
                'url' => $siteUrl,
            ];
        }

        return $data;
    }

    /** @param array{title:string,description:string,language:string} $site
     *  @return array<string, mixed> */
    public static function webPage(string $name, string $description, string $canonical, array $site): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $name,
            'description' => $description,
            'url' => $canonical,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => self::siteName($site),
                'url' => self::siteRoot($canonical),
            ],
            'inLanguage' => $site['language'],
        ];
    }

    /** @param array{title:string,description:string,language:string} $site
     *  @return array<string, mixed> */
    public static function collectionPage(string $name, string $description, string $canonical, array $site): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $description,
            'url' => $canonical,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => self::siteName($site),
                'url' => self::siteRoot($canonical),
            ],
            'inLanguage' => $site['language'],
        ];
    }

    /** @param array{title:string,description:string,language:string} $site */
    private static function siteName(array $site): string
    {
        $name = trim($site['title']);

        return $name === '' ? '日记Planner' : $name;
    }

    private static function siteRoot(string $canonical): string
    {
        $parts = parse_url($canonical);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
