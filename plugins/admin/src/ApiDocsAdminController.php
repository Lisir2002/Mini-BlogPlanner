<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Config\Env;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;

/**
 * 后台接口文档页与 OpenAPI 源文件。
 *
 * 文档只在管理员会话内渲染；YAML 不放在 public 目录，避免被静态文件规则直接暴露。
 */
final class ApiDocsAdminController
{
    private readonly AdminGuard $guard;
    private readonly string $source;

    public function __construct(
        private readonly Theme $theme,
        string $root,
    ) {
        $this->guard = new AdminGuard($theme);
        $this->source = rtrim($root, '/') . '/docs/openapi.yaml';
    }

    public function page(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $html = $this->theme->render('admin/api-docs', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => '接口文档',
            'current' => 'apidocs',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'body_class' => 'admin-docs-body',
            'page_styles' => ['admin-api-docs.css'],
            'page_scripts' => ['scalar.js'],
        ], 'admin/layout');

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store, private, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function openapi(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        if (!is_file($this->source) || !is_readable($this->source)) {
            return Response::text('OpenAPI source is unavailable.', 503)
                ->withHeader('Cache-Control', 'no-store');
        }

        $yaml = (string) file_get_contents($this->source);

        return Response::binary($yaml, 'application/yaml; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, private, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
