<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
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

    public function index(Request $request): Response
    {
        try {
            $tags = (new TagRepository(Connection::fromEnv()))->all();
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取标签，请稍后再试。', $this->version, $this->site());
        }

        return $this->render('tags', [
            'page_title' => '标签',
            'current' => 'tags',
            'tags' => $tags,
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
            $tag = (new TagRepository($db))->findBySlug($slug);
            $posts = $tag === null ? [] : (new PostRepository($db))->byTag($slug, 20);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取标签，请稍后再试。', $this->version, $this->site());
        }

        if ($tag === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        return $this->render('tag', [
            'page_title' => $tag['name'],
            'current' => 'tags',
            'tag' => $tag,
            'posts' => $posts,
        ]);
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
