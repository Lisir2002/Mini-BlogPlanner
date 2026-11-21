<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Seo\StructuredData;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class PostListController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    /** @param array<string, string> $params */
    public function __invoke(Request $request, array $params = []): Response
    {
        $requestedPage = $this->requestedPage($params['page'] ?? null);
        if ($requestedPage === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        try {
            $db = Connection::fromEnv();
            $repository = new PostRepository($db);
            $total = $repository->countPublished();
            $state = Pagination::resolve($requestedPage, $total);
            if ($requestedPage > 1 && $requestedPage > $state['pages']) {
                return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
            }
            $posts = $repository->published($state['per_page'], $state['offset']);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取文章，请稍后再试。', $this->version, $this->site());
        }

        $baseUrl = $this->baseUrl($request);
        $page = $state['page'];
        $pages = $state['pages'];
        $canonical = $page <= 1 ? $baseUrl . '/posts.html' : $baseUrl . '/posts/page/' . $page . '.html';
        $pageLinks = [];
        foreach (Pagination::window($page, $pages) as $number) {
            $pageLinks[] = [
                'number' => $number,
                'url' => $number === null ? null : ($number <= 1 ? $baseUrl . '/posts.html' : $baseUrl . '/posts/page/' . $number . '.html'),
            ];
        }

        $site = $this->site();
        $html = $this->theme->render('posts', [
            'site' => $site,
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => $page > 1 ? '文章（第 ' . $page . ' 页）' : '文章',
            'page_heading' => '所有写过的东西',
            'page_description' => '按时间排列的文章列表，每页十条。',
            'current' => 'posts',
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'page_links' => $pageLinks,
            'prev_url' => $page > 1 ? ($page === 2 ? $baseUrl . '/posts.html' : $baseUrl . '/posts/page/' . ($page - 1) . '.html') : null,
            'next_url' => $page < $pages ? $baseUrl . '/posts/page/' . ($page + 1) . '.html' : null,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage('文章', '按时间排列的文章列表。', $canonical, $site),
            'page_styles' => ['posts.css'],
        ]);

        return Response::html($html);
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
