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
    public function __invoke(Request $request, array $params = []): Response
    {
        return $this->feed($request, 'rss');
    }

    public function atom(Request $request): Response
    {
        return $this->feed($request, 'atom');
    }

    public function json(Request $request): Response
    {
        return $this->feed($request, 'json');
    }

    private function feed(Request $request, string $format): Response
    {
        try {
            $posts = (new PostRepository(Connection::fromEnv()))->latest(20);
        } catch (Throwable) {
            return $this->unavailable($format);
        }

        $site = [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            'timezone' => (string) Env::get('APP_TIMEZONE', 'UTC'),
        ];
        $baseUrl = $this->baseUrl($request);
        $appVersion = $this->version->app();

        [$body, $contentType] = match ($format) {
            'atom' => [
                FeedBuilder::atom($posts, $site, $baseUrl, $appVersion),
                'application/atom+xml; charset=utf-8',
            ],
            'json' => [
                FeedBuilder::json($posts, $site, $baseUrl, $appVersion),
                'application/feed+json; charset=utf-8',
            ],
            default => [
                FeedBuilder::rss($posts, $site, $baseUrl, $appVersion),
                'application/rss+xml; charset=utf-8',
            ],
        };

        return Response::binary($body, $contentType)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    private function unavailable(string $format): Response
    {
        return match ($format) {
            'atom' => Response::binary(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Feed unavailable</title><updated>' . gmdate(DATE_ATOM) . '</updated></feed>',
                'application/atom+xml; charset=utf-8',
                503,
            )->withHeader('Cache-Control', 'no-store'),
            'json' => Response::binary(
                '{"error":"Feed temporarily unavailable."}',
                'application/feed+json; charset=utf-8',
                503,
            )->withHeader('Cache-Control', 'no-store'),
            default => Response::binary(
                '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed unavailable</title><description>Feed temporarily unavailable.</description></channel></rss>',
                'application/rss+xml; charset=utf-8',
                503,
            )->withHeader('Cache-Control', 'no-store'),
        };
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
