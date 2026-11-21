<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\Post;
use Core\Content\PostRepository;
use Core\Content\SearchTerm;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class SearchController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    public function __invoke(Request $request): Response
    {
        $query = SearchTerm::normalize($request->query()['q'] ?? null);
        $posts = [];

        if ($query !== '') {
            try {
                $posts = (new PostRepository(Connection::fromEnv()))->search($query);
            } catch (Throwable) {
                return ErrorPage::page($this->theme, $request, 503, '暂时无法搜索文章，请稍后再试。', $this->version, $this->site());
            }
        }

        $items = array_map(fn (Post $post): array => [
            'post' => $post,
            'snippet' => $this->snippet($post, $query),
        ], $posts);

        $html = $this->theme->render('search', [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => $query === '' ? '搜索' : '搜索：' . $query,
            'page_heading' => $query === '' ? '翻一翻这本手账' : '搜索：' . $query,
            'page_description' => '搜索文章标题、摘要与正文。',
            'current' => 'search',
            'query' => $query,
            'items' => $items,
            'page_styles' => ['posts.css', 'search.css'],
        ]);

        return Response::html($html)->withHeader('X-Robots-Tag', 'noindex, follow');
    }

    private function snippet(Post $post, string $query): string
    {
        $excerpt = trim($post->excerpt);
        $text = preg_replace('/<[^>]+>/u', ' ', $post->body);
        $text = is_string($text) ? html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $text = preg_replace('/[\s\x{3000}]+/u', ' ', trim($text));
        $text = is_string($text) ? $text : '';

        if ($query !== '' && $text !== '') {
            $needle = mb_strtolower($query, 'UTF-8');
            $haystack = mb_strtolower($text, 'UTF-8');
            $position = mb_strpos($haystack, $needle, 0, 'UTF-8');
            if ($position !== false) {
                $start = max(0, $position - 28);
                $snippet = mb_substr($text, $start, 110, 'UTF-8');

                return ($start > 0 ? '…' : '') . $snippet . ($start + 110 < mb_strlen($text, 'UTF-8') ? '…' : '');
            }
        }

        if ($excerpt !== '') {
            return $excerpt;
        }
        if ($text === '') {
            return '这篇文章没有可展示的摘要。';
        }

        return mb_substr($text, 0, 120, 'UTF-8') . (mb_strlen($text, 'UTF-8') > 120 ? '…' : '');
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
