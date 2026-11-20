<?php

declare(strict_types=1);

namespace Plugin\Web;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\PageRepository;
use Core\Seo\SitemapBuilder;
use Core\Version\Version;
use Throwable;

final class SeoController
{
    public function __construct(private readonly Version $version) {}

    public function sitemap(Request $request): Response
    {
        $baseUrl = $this->baseUrl($request);
        $root = dirname(__DIR__, 3);
        $pages = (new PageRepository($root, $this->version))->slugs();
        $posts = [];
        $tags = [];

        try {
            $db = Connection::fromEnv();
            $posts = (new PostRepository($db))->publishedForSitemap();
            $tags = (new TagRepository($db))->all();
        } catch (Throwable) {
            // 数据库不可用时仍返回静态入口，避免整份 sitemap 失效。
        }

        $entries = [
            ['loc' => $baseUrl . '/', 'changefreq' => 'daily', 'priority' => 1.0],
            ['loc' => $baseUrl . '/posts.html', 'changefreq' => 'daily', 'priority' => 0.8],
            ['loc' => $baseUrl . '/tags.html', 'changefreq' => 'weekly', 'priority' => 0.6],
        ];

        foreach ($pages as $slug) {
            $entries[] = [
                'loc' => $baseUrl . '/' . rawurlencode($slug) . '.html',
                'changefreq' => 'monthly',
                'priority' => 0.5,
            ];
        }

        foreach ($posts as $post) {
            $lastModified = substr($post->updatedAt !== '' ? $post->updatedAt : $post->publishedAt, 0, 10);
            $entries[] = [
                'loc' => $baseUrl . '/posts/' . rawurlencode($post->slug) . '.html',
                'lastmod' => $lastModified,
                'changefreq' => 'monthly',
                'priority' => 0.8,
            ];
        }

        foreach ($tags as $tag) {
            $entries[] = [
                'loc' => $baseUrl . '/tags/' . rawurlencode($tag['slug']) . '.html',
                'changefreq' => 'monthly',
                'priority' => 0.5,
            ];
        }

        return Response::binary(SitemapBuilder::render($entries), 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        $baseUrl = $this->baseUrl($request);
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /search',
            'Disallow: /api/v1',
            'Sitemap: ' . $baseUrl . '/sitemap.xml',
            '',
        ]);

        return Response::text($body)
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function baseUrl(Request $request): string
    {
        $configured = trim((string) Env::get('APP_URL', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = trim((string) ($request->header('host') ?? 'localhost'));

        return 'http://' . ($host === '' ? 'localhost' : $host);
    }
}
