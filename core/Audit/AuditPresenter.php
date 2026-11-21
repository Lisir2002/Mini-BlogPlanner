<?php

declare(strict_types=1);

namespace Core\Audit;

/**
 * 把审计事件转成页面可直接展示的文案与字段。
 *
 * 事件在数据库里保持原始 code，展示层负责分类、命名和脱敏；
 * 旧记录里的敏感字段也会在读取时被遮蔽，避免只在新写入时生效。
 */
final class AuditPresenter
{
    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'admin' => '后台与账户',
        'post' => '内容',
        'comment' => '互动',
        'media' => '媒体',
        'tag' => '标签',
        'system' => '系统',
        'other' => '其他',
    ];

    /** @var array<string, string> */
    private const EVENT_LABELS = [
        'admin.login.succeeded' => '登录成功',
        'admin.login.failed' => '登录失败',
        'admin.logout' => '退出登录',
        'admin.update.started' => '启动版本更新',
        'admin.audit.exported' => '导出审计记录',
        'post.created' => '创建文章',
        'post.published' => '发布文章',
        'post.updated' => '更新文章',
        'post.archived' => '归档文章',
        'post.restored' => '恢复文章',
        'post.bulk_archived' => '批量归档文章',
        'post.bulk_restored' => '批量恢复文章',
        'post.draft_discarded' => '丢弃工作稿',
        'post.revision_restored' => '恢复历史版本',
        'media.uploaded' => '上传媒体',
        'media.deleted' => '删除媒体',
        'tag.renamed' => '重命名标签',
        'tag.merged' => '合并标签',
        'tag.deleted' => '删除标签',
        'comment.created' => '收到新留言',
        'comment.approved' => '通过留言',
        'comment.spam' => '标记垃圾留言',
        'comment.deleted' => '删除留言',
        'system.upgrade.started' => '升级开始',
        'system.upgrade.resumed' => '升级续跑',
        'system.upgrade.backup' => '升级前备份',
        'system.upgrade.migrated' => '执行数据库迁移',
        'system.upgrade.succeeded' => '升级完成',
        'system.upgrade.failed' => '升级失败',
        'system.rollback.succeeded' => '回滚完成',
        'system.rollback.failed' => '回滚失败',
        'system.audit.pruned' => '清理审计日志',
    ];

    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'id' => '记录 ID',
        'post_id' => '文章 ID',
        'comment_id' => '留言 ID',
        'parent_id' => '父留言 ID',
        'slug' => '文章别名',
        'status' => '状态',
        'version' => '版本',
        'tag' => '版本',
        'release' => '发布版本',
        'from' => '原版本',
        'to' => '目标版本',
        'ip' => '来源 IP',
        'count' => '数量',
        'mime' => '文件类型',
        'size' => '文件大小',
        'plan_id' => '升级计划',
        'backup_id' => '备份编号',
        'before' => '清理边界',
        'days' => '保留天数',
        'deleted' => '删除条数',
        'source' => '来源标签',
        'target' => '目标标签',
        'name' => '名称',
        'action' => '动作',
        'query' => '筛选条件',
        'rows' => '导出行数',
    ];

    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'csrf',
        'cookie',
        'authorization',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'session_id',
    ];

    public static function category(string $event): string
    {
        $event = strtolower(trim($event));
        if (str_starts_with($event, 'admin.')) {
            return 'admin';
        }
        if (str_starts_with($event, 'post.')) {
            return 'post';
        }
        if (str_starts_with($event, 'comment.')) {
            return 'comment';
        }
        if (str_starts_with($event, 'media.')) {
            return 'media';
        }
        if (str_starts_with($event, 'tag.')) {
            return 'tag';
        }
        if (str_starts_with($event, 'system.')) {
            return 'system';
        }

        return 'other';
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? self::CATEGORY_LABELS['other'];
    }

    public static function label(string $event): string
    {
        $event = trim($event);
        if (isset(self::EVENT_LABELS[$event])) {
            return self::EVENT_LABELS[$event];
        }

        $tail = str_contains($event, '.') ? substr($event, (int) strrpos($event, '.') + 1) : $event;
        $tail = trim(str_replace('_', ' ', $tail));

        return $tail === '' ? $event : $tail;
    }

    public static function isFailure(string $event): bool
    {
        return str_ends_with(strtolower(trim($event)), '.failed');
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload, int $depth = 0): array
    {
        if ($depth >= 5) {
            return ['value' => '[层级过深，已截断]'];
        }

        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $clean[$key] = '[已脱敏]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value, $depth + 1);
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function summary(string $event, array $payload): string
    {
        $preferred = [
            'release',
            'tag',
            'slug',
            'status',
            'ip',
            'count',
            'mime',
            'size',
            'before',
            'days',
            'deleted',
            'id',
            'post_id',
            'parent_id',
            'source',
            'target',
            'name',
            'version',
        ];
        $parts = [];
        foreach ($preferred as $key) {
            if (!array_key_exists($key, $payload) || is_array($payload[$key])) {
                continue;
            }
            $value = self::formatValue($key, $payload[$key]);
            if ($value === '') {
                continue;
            }
            $parts[] = self::fieldLabel($key) . ' ' . $value;
            if (count($parts) >= 4) {
                break;
            }
        }

        if ($parts === []) {
            foreach ($payload as $key => $value) {
                if (is_array($value) || $value === null || $value === '') {
                    continue;
                }
                $parts[] = self::fieldLabel((string) $key) . ' ' . self::formatValue((string) $key, $value);
                if (count($parts) >= 3) {
                    break;
                }
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{key:string,label:string,value:string}>
     */
    public static function fields(array $payload): array
    {
        $rows = [];
        foreach (self::redact($payload) as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $value = is_string($value) ? mb_substr($value, 0, 240) : '[]';
            }
            $rows[] = [
                'key' => (string) $key,
                'label' => self::fieldLabel((string) $key),
                'value' => self::formatValue((string) $key, $value),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload): string
    {
        $json = json_encode(
            self::redact($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        return $json;
    }

    private static function fieldLabel(string $key): string
    {
        return self::FIELD_LABELS[$key] ?? $key;
    }

    private static function formatValue(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if ($value === null) {
            return '';
        }
        if ($key === 'size' && is_numeric($value)) {
            $bytes = (float) $value;
            if ($bytes >= 1048576) {
                return number_format($bytes / 1048576, 1) . ' MB';
            }
            if ($bytes >= 1024) {
                return number_format($bytes / 1024, 1) . ' KB';
            }

            return (string) (int) $bytes . ' B';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }
}
