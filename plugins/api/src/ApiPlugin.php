<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Http\Request;
use Core\Http\Response;
use Core\Plugin\PluginInterface;
use Core\Routing\Route;
use Core\Version\Version;

final class ApiPlugin implements PluginInterface
{
    public function __construct(private readonly Version $version) {}

    public function id(): string
    {
        return 'api';
    }

    public function priority(): int
    {
        return 30;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $health = new HealthController($this->version);
        $posts = new PostApiController();
        $tags = new TagApiController();

        return [
            new Route(['GET'], '/api/v1/health', static fn (Request $request, array $params): Response => $health($request), 'api.health'),
            new Route(['GET'], '/healthz', static fn (Request $request, array $params): Response => $health($request), 'api.health.alias'),
            new Route(['GET'], '/api/v1/posts', static fn (Request $request, array $params): Response => $posts->index($request), 'api.posts.index'),
            new Route(['GET'], '/api/v1/posts/{slug}', static fn (Request $request, array $params): Response => $posts->show($request, $params), 'api.posts.show'),
            new Route(['GET'], '/api/v1/tags', static fn (Request $request, array $params): Response => $tags->index($request), 'api.tags.index'),
            new Route(['GET'], '/api/v1/tags/{slug}', static fn (Request $request, array $params): Response => $tags->show($request, $params), 'api.tags.show'),
        ];
    }
}
