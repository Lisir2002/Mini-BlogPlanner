<?php

declare(strict_types=1);

namespace Plugin\Pages;

use Core\Config\Env;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\PageRepository;
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

        $html = $this->theme->render($page->template(), [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => $page->title(),
            'current' => 'page',
            'content' => $page->content(),
            'page_styles' => $page->styles(),
            'page_scripts' => $page->scripts(),
        ]);

        return Response::html($html);
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
