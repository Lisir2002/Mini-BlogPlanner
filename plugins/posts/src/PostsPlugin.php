<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Plugin\PluginInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Route;
use Core\Theme\Theme;
use Core\Version\Version;

final class PostsPlugin implements PluginInterface
{
    private readonly Theme $theme;

    public function __construct(private readonly Version $version)
    {
        $root = dirname(__DIR__, 3);
        $themeId = (string) (Env::get('APP_THEME', 'cream') ?? 'cream');
        $this->theme = Theme::load($root, $themeId);
    }

    public function id(): string
    {
        return 'posts';
    }

    public function priority(): int
    {
        return 25;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $tags = new TagController($this->version, $this->theme);
        $comments = new CommentController($this->version, $this->theme);

        return [
            new Route(['GET'], '/posts', new PostListController($this->version, $this->theme), 'posts.index'),
            new Route(['GET'], '/posts/{slug}', new PostController($this->version, $this->theme), 'posts.show'),
            new Route(['POST'], '/posts/{slug}/comments', static fn (Request $request, array $params): Response => $comments->submit($request, $params), 'posts.comments.store'),
            new Route(['GET'], '/tags', static fn (Request $request, array $params): Response => $tags->index($request), 'tags.index'),
            new Route(['GET'], '/tags/{slug}', static fn (Request $request, array $params): Response => $tags->show($request, $params), 'tags.show'),
        ];
    }
}
