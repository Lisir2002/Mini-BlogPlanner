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
        $admin = new AdminApiController($this->version);
        $tagAdmin = new TagAdminApiController();
        $commentAdmin = new CommentAdminApiController();

        return [
            new Route(['GET'], '/api/v1/health', static fn (Request $request, array $params): Response => $health($request), 'api.health'),
            new Route(['GET'], '/api/v1/status', static fn (Request $request, array $params): Response => $health->status($request), 'api.status'),
            new Route(['GET'], '/healthz', static fn (Request $request, array $params): Response => $health($request), 'api.health.alias'),
            new Route(['GET'], '/api/v1/posts', static fn (Request $request, array $params): Response => $posts->index($request), 'api.posts.index'),
            new Route(['GET'], '/api/v1/search', static fn (Request $request, array $params): Response => $posts->search($request), 'api.search'),
            new Route(['GET'], '/api/v1/posts/{slug}', static fn (Request $request, array $params): Response => $posts->show($request, $params), 'api.posts.show'),
            new Route(['GET'], '/api/v1/tags', static fn (Request $request, array $params): Response => $tags->index($request), 'api.tags.index'),
            new Route(['GET'], '/api/v1/tags/{slug}', static fn (Request $request, array $params): Response => $tags->show($request, $params), 'api.tags.show'),
            new Route(['GET'], '/api/v1/admin/status', static fn (Request $request, array $params): Response => $admin->status($request), 'api.admin.status'),
            new Route(['GET'], '/api/v1/admin/posts', static fn (Request $request, array $params): Response => $admin->posts($request), 'api.admin.posts'),
            new Route(['GET'], '/api/v1/admin/comments', static fn (Request $request, array $params): Response => $commentAdmin->index($request), 'api.admin.comments.index'),
            new Route(['PATCH'], '/api/v1/admin/comments/{id}', static fn (Request $request, array $params): Response => $commentAdmin->update($request, $params), 'api.admin.comments.update'),
            new Route(['DELETE'], '/api/v1/admin/comments/{id}', static fn (Request $request, array $params): Response => $commentAdmin->delete($request, $params), 'api.admin.comments.delete'),
            new Route(['POST'], '/api/v1/admin/comments/bulk', static fn (Request $request, array $params): Response => $commentAdmin->bulk($request), 'api.admin.comments.bulk'),
            new Route(['GET'], '/api/v1/admin/tags', static fn (Request $request, array $params): Response => $tagAdmin->index($request), 'api.admin.tags.index'),
            new Route(['POST'], '/api/v1/admin/tags', static fn (Request $request, array $params): Response => $tagAdmin->create($request), 'api.admin.tags.create'),
            new Route(['GET'], '/api/v1/admin/tags/{id}', static fn (Request $request, array $params): Response => $tagAdmin->show($request, $params), 'api.admin.tags.show'),
            new Route(['PATCH'], '/api/v1/admin/tags/{id}', static fn (Request $request, array $params): Response => $tagAdmin->update($request, $params), 'api.admin.tags.update'),
            new Route(['POST'], '/api/v1/admin/tags/{id}/merge', static fn (Request $request, array $params): Response => $tagAdmin->merge($request, $params), 'api.admin.tags.merge'),
            new Route(['DELETE'], '/api/v1/admin/tags/{id}', static fn (Request $request, array $params): Response => $tagAdmin->delete($request, $params), 'api.admin.tags.delete'),
        ];
    }
}
