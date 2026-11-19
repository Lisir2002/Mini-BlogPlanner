<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
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

    public function __invoke(Request $request): Response
    {
        try {
            $posts = (new PostRepository(Connection::fromEnv()))->latest(20);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取文章，请稍后再试。', $this->version, $this->site());
        }

        $html = $this->theme->render('posts', [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => '文章',
            'current' => 'posts',
            'posts' => $posts,
            'page_styles' => ['posts.css'],
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
