<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Seo\StructuredData;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class ArchiveController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    public function index(Request $request): Response
    {
        try {
            $months = (new PostRepository(Connection::fromEnv()))->archiveMonths();
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取归档，请稍后再试。', $this->version, $this->site());
        }

        $years = [];
        foreach ($months as $month) {
            $year = $month['year'];
            if (!isset($years[$year])) {
                $years[$year] = ['year' => $year, 'count' => 0, 'months' => []];
            }
            $years[$year]['count'] += $month['count'];
            $years[$year]['months'][] = $month;
        }

        $canonical = $this->baseUrl($request) . '/archive.html';
        $site = $this->site();

        return $this->render('archive', [
            'page_title' => '归档',
            'page_description' => '按年份和月份翻看写过的文章。',
            'years' => array_values($years),
            'total' => array_sum(array_map(static fn (array $year): int => $year['count'], $years)),
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage('归档', '按年份和月份翻看写过的文章。', $canonical, $site),
        ]);
    }

    /** @param array<string, string> $params */
    public function year(Request $request, array $params): Response
    {
        $year = $this->yearValue((string) ($params['year'] ?? ''));
        if ($year === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        try {
            $repository = new PostRepository(Connection::fromEnv());
            $posts = $repository->publishedInPeriod($year, null, 500);
            $months = $repository->archiveMonths();
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取归档，请稍后再试。', $this->version, $this->site());
        }

        $yearMonths = array_values(array_filter(
            $months,
            static fn (array $month): bool => $month['year'] === $year,
        ));
        if ($yearMonths === []) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        $groups = [];
        foreach ($yearMonths as $month) {
            $groups[$month['month']] = [
                'month' => $month['month'],
                'count' => $month['count'],
                'posts' => [],
            ];
        }
        foreach ($posts as $post) {
            $monthNumber = (int) substr($post->publishedAt, 5, 2);
            if (isset($groups[$monthNumber])) {
                $groups[$monthNumber]['posts'][] = $post;
            }
        }
        krsort($groups);

        $canonical = $this->baseUrl($request) . '/archive/' . $year . '.html';
        $site = $this->site();

        return $this->render('archive-year', [
            'page_title' => $year . ' 年归档',
            'page_description' => $year . ' 年写过的文章，按月份分组。',
            'year' => $year,
            'year_count' => array_sum(array_map(static fn (array $month): int => $month['count'], $yearMonths)),
            'months' => array_values($groups),
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage($year . ' 年归档', $year . ' 年写过的文章，按月份分组。', $canonical, $site),
        ]);
    }

    /** @param array<string, string> $params */
    public function month(Request $request, array $params): Response
    {
        $year = $this->yearValue((string) ($params['year'] ?? ''));
        $monthRaw = (string) ($params['month'] ?? '');
        if ($year === null || preg_match('/^\d{1,2}$/', $monthRaw) !== 1) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        $month = (int) $monthRaw;
        if ($month < 1 || $month > 12) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $baseUrl = $this->baseUrl($request);
        $monthUrl = $baseUrl . '/archive/' . $year . '/' . $monthPadded . '.html';
        if ($monthRaw !== $monthPadded) {
            return Response::redirect($monthUrl, 301);
        }

        $page = 1;
        if (isset($params['page'])) {
            if (preg_match('/^\d+$/', (string) $params['page']) !== 1) {
                return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
            }
            $page = (int) $params['page'];
            if ($page === 1) {
                return Response::redirect($monthUrl, 301);
            }
        }

        try {
            $db = Connection::fromEnv();
            $repository = new PostRepository($db);
            $total = $repository->countPublishedInPeriod($year, $month);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            if ($total === 0 || $page < 1 || $page > $pages) {
                return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
            }
            $posts = $repository->publishedInPeriod(
                $year,
                $month,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
            );
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取归档，请稍后再试。', $this->version, $this->site());
        }

        $canonical = $this->monthPageUrl($baseUrl, $year, $month, $page);
        $title = $year . ' 年 ' . $month . ' 月';
        if ($page > 1) {
            $title .= '（第 ' . $page . ' 页）';
        }
        $site = $this->site();

        return $this->render('archive-month', [
            'page_title' => $title,
            'page_description' => $year . ' 年 ' . $month . ' 月写过的文章。',
            'year' => $year,
            'month' => $month,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'posts' => $posts,
            'month_url' => $monthUrl,
            'prev_url' => $page > 1 ? $this->monthPageUrl($baseUrl, $year, $month, $page - 1) : null,
            'next_url' => $page < $pages ? $this->monthPageUrl($baseUrl, $year, $month, $page + 1) : null,
            'canonical_url' => $canonical,
            'json_ld' => StructuredData::collectionPage($title, $year . ' 年 ' . $month . ' 月写过的文章。', $canonical, $site),
        ]);
    }

    private function monthPageUrl(string $baseUrl, int $year, int $month, int $page): string
    {
        $monthUrl = $baseUrl . '/archive/' . $year . '/' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.html';

        return $page <= 1 ? $monthUrl : $baseUrl . '/archive/' . $year . '/' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '/page/' . $page . '.html';
    }

    private function yearValue(string $raw): ?int
    {
        if (preg_match('/^\d{4}$/', $raw) !== 1) {
            return null;
        }

        $year = (int) $raw;

        return $year >= 1970 && $year <= 2100 ? $year : null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $html = $this->theme->render($template, $data + [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'current' => 'archive',
            'page_styles' => ['posts.css', 'archive.css'],
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
