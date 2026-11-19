<?php

declare(strict_types=1);

namespace Plugin\Web;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class HomeController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $posts = (new PostRepository(Connection::fromEnv()))->latest(4);
        } catch (Throwable) {
            $posts = [];
        }

        $site = [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];

        $html = $this->theme->render('home', [
            'site' => $site,
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => (string) Env::get('APP_NAME', '日记Planner'),
            'current' => 'home',
            'posts' => $posts,
        ]);

        return Response::html($html);
    }
}
