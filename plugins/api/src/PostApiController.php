<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Config\Env;
use Core\Content\Post;
use Core\Content\PostResource;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

final class PostApiController
{
    public function index(Request $request): Response
    {
        try {
            $posts = (new PostRepository(Connection::fromEnv()))->latest(50);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(static fn (Post $post): array => PostResource::summary($post), $posts),
            'meta' => ['count' => count($posts)],
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        try {
            $post = (new PostRepository(Connection::fromEnv()))->findPublished((string) ($params['slug'] ?? ''));
        } catch (Throwable) {
            return $this->unavailable($request);
        }
        if ($post === null) {
            return Response::problem([
                'type' => '/problems/post-not-found',
                'title' => 'Post Not Found',
                'status' => 404,
                'detail' => '文章不存在或尚未发布。',
                'instance' => $request->path(),
            ], 404);
        }

        return Response::json(['data' => PostResource::detail($post)]);
    }

    private function unavailable(Request $request): Response
    {
        return Response::problem([
            'type' => '/problems/service-unavailable',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => '文章服务暂时不可用。',
            'instance' => $request->path(),
        ], 503);
    }
}
