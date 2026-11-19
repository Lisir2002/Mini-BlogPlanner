<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Content\Post;
use Core\Content\PostRepository;
use Core\Content\PostResource;
use Core\Content\TagRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

final class TagApiController
{
    public function index(Request $request): Response
    {
        try {
            $tags = (new TagRepository(Connection::fromEnv()))->all();
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(fn (array $tag): array => $this->resource($tag), $tags),
            'meta' => ['count' => count($tags)],
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        if (preg_match('/^[a-z0-9-]{1,80}$/', $slug) !== 1) {
            return $this->notFound($request);
        }

        try {
            $db = Connection::fromEnv();
            $tag = (new TagRepository($db))->findBySlug($slug);
            $posts = $tag === null ? [] : (new PostRepository($db))->byTag($slug, 50);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        if ($tag === null) {
            return $this->notFound($request);
        }

        return Response::json([
            'data' => [
                'name' => $tag['name'],
                'slug' => $tag['slug'],
                'url' => '/tags/' . $tag['slug'] . '.html',
                'posts' => array_map(static fn (Post $post): array => PostResource::summary($post), $posts),
            ],
        ]);
    }

    /** @param array{name:string,slug:string,count:int} $tag
     *  @return array<string, mixed>
     */
    private function resource(array $tag): array
    {
        return [
            'name' => $tag['name'],
            'slug' => $tag['slug'],
            'url' => '/tags/' . $tag['slug'] . '.html',
            'post_count' => $tag['count'],
        ];
    }

    private function notFound(Request $request): Response
    {
        return Response::problem([
            'type' => '/problems/tag-not-found',
            'title' => 'Tag Not Found',
            'status' => 404,
            'detail' => '标签不存在或没有已发布文章。',
            'instance' => $request->path(),
        ], 404);
    }

    private function unavailable(Request $request): Response
    {
        return Response::problem([
            'type' => '/problems/service-unavailable',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => '标签服务暂时不可用。',
            'instance' => $request->path(),
        ], 503);
    }
}
