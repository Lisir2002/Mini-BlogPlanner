<?php

declare(strict_types=1);

namespace Core\Api;

/**
 * 管理 API 的作用域定义。
 */
final class ApiScope
{
    /** @var array<string, string> */
    public const LABELS = [
        'status:read' => '查看运行状态',
        'content:read' => '查看内容列表',
        'comments:read' => '查看留言列表',
        'tags:read' => '查看后台标签统计与列表',
        'tags:write' => '创建、重命名、合并和删除标签',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $scope): string
    {
        return self::LABELS[$scope] ?? $scope;
    }
}
