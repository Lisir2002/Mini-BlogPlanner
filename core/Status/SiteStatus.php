<?php

declare(strict_types=1);

namespace Core\Status;

use Core\Database\Connection;
use Core\Version\SchemaGuard;
use Core\Version\SchemaGuardException;
use Core\Version\Version;
use Throwable;

/**
 * 站点运行状态汇总。
 *
 * 只输出公开可读的版本、连接、结构和内容数量，
 * 不返回数据库地址、账号、路径或密钥。
 */
final class SiteStatus
{
    public function __construct(private readonly Version $version) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $started = microtime(true);
        $base = [
            'status' => 'ok',
            'app' => $this->version->app(),
            'api' => $this->version->api(),
            'channel' => $this->version->channel(),
            'checked_at' => gmdate('c'),
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'timezone' => date_default_timezone_get(),
            ],
        ];

        try {
            $db = Connection::fromEnv();
            $schema = new SchemaGuard($db, $this->version);
            $schema->assertCompatible();
            $actualSchema = $schema->actual();

            $server = $db->fetchOne('SELECT VERSION() AS version, NOW() AS server_time');
            $content = $db->fetchOne(
                "SELECT
                    (SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at <= NOW()) AS published_posts,
                    (SELECT COUNT(*) FROM comments WHERE status = 'approved') AS approved_comments,
                    (SELECT COUNT(*) FROM media) AS media,
                    (SELECT COUNT(*) FROM tags) AS tags,
                    (SELECT MAX(published_at) FROM posts WHERE status = 'published' AND published_at <= NOW()) AS last_published_at"
            );
            $latency = (int) round((microtime(true) - $started) * 1000);

            return $base + [
                'schema' => $actualSchema,
                'latency_ms' => $latency,
                'database' => [
                    'connected' => true,
                    'driver' => 'mysql',
                    'version' => (string) ($server['version'] ?? ''),
                    'server_time' => (string) ($server['server_time'] ?? ''),
                    'latency_ms' => $latency,
                ],
                'content' => [
                    'published_posts' => (int) ($content['published_posts'] ?? 0),
                    'approved_comments' => (int) ($content['approved_comments'] ?? 0),
                    'media' => (int) ($content['media'] ?? 0),
                    'tags' => (int) ($content['tags'] ?? 0),
                    'last_published_at' => (string) ($content['last_published_at'] ?? ''),
                ],
                'checks' => [
                    [
                        'id' => 'database',
                        'label' => '数据库连接',
                        'status' => 'ok',
                        'detail' => '连接正常，可以读取站点数据。',
                        'metric' => $latency . ' ms',
                    ],
                    [
                        'id' => 'schema',
                        'label' => '结构版本',
                        'status' => 'ok',
                        'detail' => '数据库结构与当前发布一致。',
                        'metric' => 'schema ' . $actualSchema,
                    ],
                    [
                        'id' => 'content',
                        'label' => '内容读取',
                        'status' => 'ok',
                        'detail' => '文章、留言、媒体和标签计数可正常读取。',
                        'metric' => (int) ($content['published_posts'] ?? 0) . ' 篇已发布',
                    ],
                ],
            ];
        } catch (SchemaGuardException $exception) {
            return $base + [
                'status' => 'degraded',
                'schema' => $exception->actual,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'database' => ['connected' => true, 'driver' => 'mysql'],
                'checks' => [[
                    'id' => 'schema',
                    'label' => '结构版本',
                    'status' => 'error',
                    'detail' => '数据库结构与当前发布不一致，需要升级或回退。',
                    'metric' => $exception->actual === null ? '未安装' : 'schema ' . $exception->actual,
                ]],
                'error' => 'schema-mismatch',
            ];
        } catch (Throwable) {
            return $base + [
                'status' => 'degraded',
                'schema' => null,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'database' => ['connected' => false, 'driver' => 'mysql'],
                'checks' => [[
                    'id' => 'database',
                    'label' => '数据库连接',
                    'status' => 'error',
                    'detail' => '暂时无法连接数据库。',
                    'metric' => '不可用',
                ]],
                'error' => 'service-unavailable',
            ];
        }
    }
}
