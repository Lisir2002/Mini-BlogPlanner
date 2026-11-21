<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Seo\StructuredData;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class TagController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $requestedPage = $this->requestedPage($params['page'] ?? null);
        if ($requestedPage === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        try {
            $repository = new TagRepository(Connection::fromEnv());
            $total = $repository->countAll();
            $state = Pagination::resolve($requestedPage, $total);
            if ($requestedPage > 1 && $requestedPage > $state['pages']) {
                return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
            }
            $tags = $repository->all($state['per_page'], $state['offset']);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取标签，请稍后再试。', $this->version, $this->site());
        }

        $baseUrl = $this->baseUrl($request);
        $page = $state['page'];
        $pages = $state['pages'];
        $canonical = $page <= 1 ? $baseUrl . '/tags.html' : $baseUrl . '/tags/page/' . $page . '.html';
        $site = $this->site();

        return $this->render('tags', [
            'page_title' => $page > 1 ? '标签（第 ' . $page . ' 页）' : '标签',
            'page_heading' => '按标签翻手账',
            'page_description' => '按主题浏览文章，每页十个标签。',
            'current' => 'tags',
            'tags' => $tags,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'page_links' => $this->pageLinks($baseUrl . '/tags.html', $baseUrl . '/tags/page/', $page, $pages),
            'prev_url' => $page > 1 ? ($page === 2 ? $baseUrl . '/tags.html' : $baseUrl . '/tags/page/' . ($page - 1) . '.html') : null,
            'next_url' => $page < $pages ? $baseUrl . '/tags/page/' . ($page + 1) . '.html' : null,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage('标签', '按主题浏览文章。', $canonical, $site),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        if (preg_match('/^[a-z0-9-]{1,80}$/', $slug) !== 1) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }
        $requestedPage = $this->requestedPage($params['page'] ?? null);
        if ($requestedPage === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        try {
            $db = Connection::fromEnv();
            $tag = (new TagRepository($db))->findBySlug($slug);
            $total = $tag === null ? 0 : (new PostRepository($db))->countByTag($slug);
            $state = Pagination::resolve($requestedPage, $total);
            if ($tag !== null && $requestedPage > 1 && $requestedPage > $state['pages']) {
                return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
            }
            $posts = $tag === null ? [] : (new PostRepository($db))->byTag($slug, $state['per_page'], $state['offset']);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取标签，请稍后再试。', $this->version, $this->site());
        }

        if ($tag === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        $baseUrl = $this->baseUrl($request);
        $page = $state['page'];
        $pages = $state['pages'];
        $tagBase = $baseUrl . '/tags/' . rawurlencode($slug);
        $canonical = $page <= 1 ? $tagBase . '.html' : $tagBase . '/page/' . $page . '.html';
        $site = $this->site();

        return $this->render('tag', [
            'page_title' => $page > 1 ? $tag['name'] . '（第 ' . $page . ' 页）' : $tag['name'],
            'page_heading' => '#' . $tag['name'],
            'page_description' => '标签「' . $tag['name'] . '」下的文章，每页十条。',
            'current' => 'tags',
            'tag' => $tag,
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'page_links' => $this->pageLinks($tagBase . '.html', $tagBase . '/page/', $page, $pages),
            'prev_url' => $page > 1 ? ($page === 2 ? $tagBase . '.html' : $tagBase . '/page/' . ($page - 1) . '.html') : null,
            'next_url' => $page < $pages ? $tagBase . '/page/' . ($page + 1) . '.html' : null,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage($tag['name'], '标签「' . $tag['name'] . '」下的文章。', $canonical, $site),
        ]);
    }

    /**
     * @return list<array{number:int|null,url:string|null}>
     */
    private function pageLinks(string $firstUrl, string $pageBase, int $page, int $pages): array
    {
        $links = [];
        foreach (Pagination::window($page, $pages) as $number) {
            $links[] = [
                'number' => $number,
                'url' => $number === null ? null : ($number <= 1 ? $firstUrl : $pageBase . $number . '.html'),
            ];
        }

        return $links;
    }

    private function requestedPage(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            return null;
        }
        $page = (int) $raw;

        return $page >= 1 && $page <= 10000 ? $page : null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $html = $this->theme->render($template, $data + [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_styles' => ['posts.css', 'tags.css'],
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
