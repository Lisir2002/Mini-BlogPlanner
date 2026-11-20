<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\SeriesRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Seo\StructuredData;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class SeriesController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    public function index(Request $request): Response
    {
        try {
            $series = (new SeriesRepository(Connection::fromEnv()))->all();
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取系列，请稍后再试。', $this->version, $this->site());
        }

        $site = $this->site();
        $canonical = $this->baseUrl($request) . '/series.html';

        return $this->render('series', [
            'page_title' => '系列',
            'page_description' => '把同一段旅程里的文章，按顺序串起来读。',
            'current' => 'series',
            'series' => $series,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage('系列', '把同一段旅程里的文章，按顺序串起来读。', $canonical, $site),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        if (preg_match('/^[a-z0-9-]{1,80}$/', $slug) !== 1) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        try {
            $db = Connection::fromEnv();
            $repository = new SeriesRepository($db);
            $series = $repository->findBySlug($slug);
            $posts = $series === null ? [] : $repository->postsForSeries($series['id']);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取系列，请稍后再试。', $this->version, $this->site());
        }

        if ($series === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        $site = $this->site();
        $canonical = $this->baseUrl($request) . '/series/' . rawurlencode($slug) . '.html';
        $description = $series['description'] !== '' ? $series['description'] : '系列「' . $series['name'] . '」里的文章，按发布时间从早到晚排列。';

        return $this->render('series-detail', [
            'page_title' => $series['name'],
            'page_description' => $description,
            'current' => 'series',
            'series' => $series,
            'posts' => $posts,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage($series['name'], $description, $canonical, $site),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $html = $this->theme->render($template, $data + [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_styles' => ['posts.css', 'series.css'],
        ]);

        return Response::html($html)->withHeader('Cache-Control', 'public, max-age=300');
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
