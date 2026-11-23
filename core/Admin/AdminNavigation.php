<?php

declare(strict_types=1);

namespace Core\Admin;

/**
 * 后台导航与页面标题的唯一来源。
 *
 * 菜单文字、链接、图标和对应页面标题必须从这里读取，避免顶栏标题、
 * 桌面导航和移动抽屉各自维护后出现差异。子页面（编辑文章、版本历史等）
 * 仍使用自己的标题，不占用一级菜单名称。
 */
final class AdminNavigation
{
    public const LOGIN_LABEL = '后台登录';

    /**
     * @var array<string, array{label:string, href:string, icon:string}>
     */
    private const SECTIONS = [
        'posts' => ['label' => '文章管理', 'href' => '/admin/posts.html', 'icon' => 'book'],
        'tags' => ['label' => '标签管理', 'href' => '/admin/tags.html', 'icon' => 'tag'],
        'comments' => ['label' => '留言管理', 'href' => '/admin/comments.html', 'icon' => 'comment'],
        'media' => ['label' => '媒体库', 'href' => '/admin/media.html', 'icon' => 'layers'],
        'audit' => ['label' => '审计日志', 'href' => '/admin/audit.html', 'icon' => 'shield'],
        'tokens' => ['label' => 'API 令牌', 'href' => '/admin/tokens.html', 'icon' => 'plug'],
        'apidocs' => ['label' => '接口文档', 'href' => '/admin/api-docs.html', 'icon' => 'book'],
        'updates' => ['label' => '版本与升级', 'href' => '/admin/updates.html', 'icon' => 'refresh'],
    ];

    /**
     * @return array<string, array{label:string, href:string, icon:string}>
     */
    public static function all(): array
    {
        return self::SECTIONS;
    }

    public static function label(string $key, string $fallback = '后台'): string
    {
        return self::SECTIONS[$key]['label'] ?? $fallback;
    }
}
