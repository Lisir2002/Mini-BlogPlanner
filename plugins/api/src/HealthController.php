<?php

declare(strict_types=1);

namespace Plugin\Api;

use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Version\SchemaAhead;
use Core\Version\SchemaBehind;
use Core\Version\SchemaGuard;
use Core\Version\SchemaGuardException;
use Core\Version\SchemaMissing;
use Core\Version\Version;
use Throwable;

final class HealthController
{
    public function __construct(private readonly Version $version) {}

    public function __invoke(Request $request): Response
    {
        try {
            $db = Connection::fromEnv();
            $guard = new SchemaGuard($db, $this->version);
            $guard->assertCompatible();

            return $this->json([
                'status' => 'ok',
                'app' => $this->version->app(),
                'api' => $this->version->api(),
                'schema' => $guard->actual(),
            ]);
        } catch (SchemaGuardException $exception) {
            $type = match ($exception::class) {
                SchemaAhead::class => 'schema-ahead',
                SchemaBehind::class => 'schema-behind',
                SchemaMissing::class => 'schema-missing',
                default => 'schema-unavailable',
            };

            return Response::problem([
                'type' => '/problems/' . $type,
                'title' => '站点结构版本不兼容',
                'status' => 503,
                'detail' => '请执行升级或恢复；Web 请求不会自动迁移。',
                'instance' => $request->path(),
            ], 503);
        } catch (Throwable) {
            return Response::problem([
                'type' => '/problems/service-unavailable',
                'title' => 'Service Unavailable',
                'status' => 503,
                'detail' => '健康检查暂时无法连接数据库。',
                'instance' => $request->path(),
            ], 503);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): Response
    {
        return Response::json($data)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
