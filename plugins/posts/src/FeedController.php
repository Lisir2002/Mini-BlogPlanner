<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\FeedBuilder;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Version\Version;
use Throwable;

final class FeedController
{
    public function __construct(private readonly Version $version) {}

    /** @param array<string, string> $params */
    public function __invoke(Request $request, array $params): Response
    {
        try {
            $posts = (new PostRepository(Connection::fromEnv()))->latest(20);
        } catch (Throwable) {
            return Response::binary(
                '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed unavailable</title><description>Feed temporarily unavailable.</description></channel></rss>',
                'application/rss+xml; charset=utf-8',
                503,
            )->withHeader('Cache-Control', 'no-store');
        }

        $site = [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            'timezone' => (string) Env::get('APP_TIMEZONE', 'UTC'),
        ];

        $xml = FeedBuilder::render($posts, $site, $this->baseUrl($request), $this->version->app());

        return Response::binary($xml, 'application/rss+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    private function baseUrl(Request $request): string
    {
        $configured = trim((string) Env::get('APP_URL', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = trim((string) ($request->header('host') ?? 'localhost'));

        return 'http://' . ($host === '' ? 'localhost' : $host);
    }
}
