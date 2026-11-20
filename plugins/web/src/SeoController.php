<?php

declare(strict_types=1);

namespace Plugin\Web;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Content\SeriesRepository;
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
        $series = [];
        $months = [];

        try {
            $db = Connection::fromEnv();
            $repository = new PostRepository($db);
            $posts = $repository->publishedForSitemap();
            $months = $repository->archiveMonths();
            $tags = (new TagRepository($db))->all();
            $series = (new SeriesRepository($db))->all();
        } catch (Throwable) {
            // 数据库不可用时仍返回静态入口，避免整份 sitemap 失效。
        }

        $entries = [
            ['loc' => $baseUrl . '/', 'changefreq' => 'daily', 'priority' => 1.0],
            ['loc' => $baseUrl . '/posts.html', 'changefreq' => 'daily', 'priority' => 0.8],
            ['loc' => $baseUrl . '/tags.html', 'changefreq' => 'weekly', 'priority' => 0.6],
            ['loc' => $baseUrl . '/series.html', 'changefreq' => 'weekly', 'priority' => 0.6],
            ['loc' => $baseUrl . '/archive.html', 'changefreq' => 'weekly', 'priority' => 0.5],
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

        foreach ($series as $item) {
            $entries[] = [
                'loc' => $baseUrl . '/series/' . rawurlencode($item['slug']) . '.html',
                'changefreq' => 'monthly',
                'priority' => 0.5,
            ];
        }

        $archiveYears = [];
        foreach ($months as $month) {
            $archiveYears[$month['year']] = true;
        }
        foreach (array_keys($archiveYears) as $year) {
            $entries[] = [
                'loc' => $baseUrl . '/archive/' . $year . '.html',
                'changefreq' => 'monthly',
                'priority' => 0.4,
            ];
        }
        foreach ($months as $month) {
            $entries[] = [
                'loc' => $baseUrl . '/archive/' . $month['year'] . '/' . str_pad((string) $month['month'], 2, '0', STR_PAD_LEFT) . '.html',
                'changefreq' => 'monthly',
                'priority' => 0.4,
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
