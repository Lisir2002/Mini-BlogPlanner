<?php

declare(strict_types=1);

namespace Plugin\Web;

use Core\Config\Env;
use Core\Http\Request;
use Core\Http\Response;
use Core\Status\SiteStatus;
use Core\Theme\Theme;
use Core\Version\Version;

final class StatusController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    public function __invoke(Request $request): Response
    {
        $report = (new SiteStatus($this->version))->report();
        $site = [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
        $html = $this->theme->render('status', [
            'site' => $site,
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => '站点状态',
            'page_heading' => '站点状态',
            'page_description' => '查看站点版本、数据库连接、结构版本和内容概览。',
            'current' => 'status',
            'report' => $report,
            'page_styles' => ['status.css'],
        ]);

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
