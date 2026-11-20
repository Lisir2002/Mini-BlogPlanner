<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Content\Post;
use Core\Content\PostResource;
use Core\Content\SeriesRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

final class SeriesApiController
{
    public function index(Request $request): Response
    {
        try {
            $series = (new SeriesRepository(Connection::fromEnv()))->all();
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        return Response::json([
            'data' => array_map(fn (array $item): array => $this->resource($item), $series),
            'meta' => ['count' => count($series)],
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
            $repository = new SeriesRepository($db);
            $series = $repository->findBySlug($slug);
            $posts = $series === null ? [] : $repository->postsForSeries($series['id'], 50);
        } catch (Throwable) {
            return $this->unavailable($request);
        }

        if ($series === null) {
            return $this->notFound($request);
        }

        return Response::json([
            'data' => [
                'name' => $series['name'],
                'slug' => $series['slug'],
                'description' => $series['description'],
                'url' => '/series/' . $series['slug'] . '.html',
                'posts' => array_map(static fn (Post $post): array => PostResource::summary($post), $posts),
            ],
        ]);
    }

    /** @param array{name:string,slug:string,description:string,count:int} $series
     *  @return array<string, mixed>
     */
    private function resource(array $series): array
    {
        return [
            'name' => $series['name'],
            'slug' => $series['slug'],
            'description' => $series['description'],
            'url' => '/series/' . $series['slug'] . '.html',
            'post_count' => $series['count'],
        ];
    }

    private function notFound(Request $request): Response
    {
        return Response::problem([
            'type' => '/problems/series-not-found',
            'title' => 'Series Not Found',
            'status' => 404,
            'detail' => '系列不存在或没有已发布文章。',
            'instance' => $request->path(),
        ], 404);
    }

    private function unavailable(Request $request): Response
    {
        return Response::problem([
            'type' => '/problems/service-unavailable',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => '系列服务暂时不可用。',
            'instance' => $request->path(),
        ], 503);
    }
}
