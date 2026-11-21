<?php

declare(strict_types=1);

namespace Plugin\Pages;

use Core\Config\Env;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\PageRepository;
use Core\Seo\StructuredData;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;

final class PageController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
        private readonly PageRepository $repository,
    ) {}

    /** @param array<string, string> $params */
    public function __invoke(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        $page = $this->repository->find($slug);
        if ($page === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        $site = $this->site();
        $canonical = $this->baseUrl($request) . '/' . rawurlencode($slug) . '.html';
        $content = $page->content();
        $heading = $page->title();
        if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $content, $matches) === 1) {
            $candidate = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($candidate !== '') {
                $heading = $candidate;
            }
            $content = preg_replace('~<h1\b[^>]*>.*?</h1>~is', '', $content, 1) ?? $content;
        }

        $html = $this->theme->render($page->template(), [
            'site' => $site,
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => $page->title(),
            'page_heading' => $heading,
            'page_description' => $site['description'],
            'current' => 'page',
            'content' => $content,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::webPage($page->title(), $site['description'], $canonical, $site),
            'page_styles' => $page->styles(),
            'page_scripts' => $page->scripts(),
        ]);

        return Response::html($html);
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

    /** @return array{title:string,description:string,language:string} */
    private function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
    }
}
