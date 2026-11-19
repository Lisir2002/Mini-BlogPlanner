<?php

declare(strict_types=1);

namespace Core\Theme;

use Core\Config\Env;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Version\Version;

final class ErrorPage
{
    /** @var array<int, true> */
    private const STATUSES = [
        400 => true,
        401 => true,
        403 => true,
        404 => true,
        405 => true,
        419 => true,
        422 => true,
        429 => true,
        500 => true,
        502 => true,
        503 => true,
        504 => true,
    ];

    /** @param array{title:string,description:string,language:string} $site */
    public static function response(
        Theme $theme,
        Version $version,
        Request $request,
        int $status,
        string $title,
        string $detail,
        array $site,
    ): Response {
        return self::render($theme, $version, $request, $status, $detail, $site, 'public');
    }

    /** @param array{title:string,description:string,language:string}|null $site */
    public static function page(
        Theme $theme,
        Request $request,
        int $status,
        string $detail = '',
        ?Version $version = null,
        ?array $site = null,
    ): Response {
        return self::render($theme, $version ?? self::version(), $request, $status, $detail, $site ?? self::site(), 'public');
    }

    public static function admin(Theme $theme, Request $request, int $status, string $detail = ''): Response
    {
        return self::render($theme, self::version(), $request, $status, $detail, self::site(), 'admin');
    }

    /** @param array{title:string,description:string,language:string} $site */
    private static function render(
        Theme $theme,
        Version $version,
        Request $request,
        int $status,
        string $detail,
        array $site,
        string $context,
    ): Response {
        $template = isset(self::STATUSES[$status]) ? 'errors/' . $status : 'errors/generic';
        $reason = self::reason($detail, $status);
        $html = $theme->render($template, [
            'site' => $site,
            'app' => $version->app(),
            'schema' => $version->schema(),
            'page_title' => $status . ' · ' . self::heading($status),
            'current' => '',
            'error_status' => $status,
            'error_context' => $context,
            'error_path' => $request->path(),
            'error_reason' => $reason,
            'csrf' => $context === 'admin' ? Csrf::token() : '',
            'admin' => null,
            'title' => $status . ' · ' . self::heading($status),
        ], $context === 'admin' ? 'admin/layout' : 'layout');

        return Response::html($html, $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private static function heading(int $status): string
    {
        return match ($status) {
            400 => '这张便签没写完整。',
            401 => '先确认一下你的身份。',
            403 => '这页被锁进抽屉了。',
            404 => '这一页没有夹在手账里。',
            405 => '这个动作放错了抽屉。',
            419 => '这一页已经过期了。',
            422 => '这张便签还需要修改。',
            429 => '翻页翻得太快了。',
            500 => '手账暂时翻不开。',
            502 => '中间那一页卡住了。',
            503 => '手账正在整理。',
            504 => '等回信等得太久了。',
            default => '手账遇到了一点问题。',
        };
    }

    private static function reason(string $detail, int $status): string
    {
        $detail = trim($detail);
        if ($detail === '' || in_array($detail, [
            'Not Found',
            'Method Not Allowed',
            'Internal Server Error',
            '请求的资源不存在。',
            '请求的页面不存在。',
            '请求的文章不存在。',
            '该地址不支持此请求方法。',
            '服务暂时不可用，请稍后再试。',
        ], true)) {
            return '';
        }

        return $detail;
    }

    private static function version(): Version
    {
        try {
            return Version::fromFile(dirname(__DIR__, 2) . '/version.json');
        } catch (\Throwable) {
            return Version::fromArray([
                'app' => '0.0.0',
                'channel' => 'stable',
                'schema' => 0,
                'schema_min' => 0,
                'api' => '0.0.0',
                'php' => '>=8.5',
                'mysql' => '>=8.4',
                'plugins' => [],
                'theme' => 'cream',
            ]);
        }
    }

    /** @return array{title:string,description:string,language:string} */
    private static function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
    }
}
