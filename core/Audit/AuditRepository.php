<?php

declare(strict_types=1);

namespace Core\Audit;

use Core\Database\ConnectionInterface;

/**
 * 审计记录的读写入口。
 *
 * 查询条件集中在 where() 里拼装，页面和 CSV 导出共用同一组条件，
 * 避免出现“页面看到 30 条、导出却是 3000 条”的口径差。
 */
final class AuditRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @param array<string, mixed> $payload */
    public function append(
        string $event,
        string $actor,
        array $payload = [],
        ?string $planId = null,
        ?string $backupId = null,
    ): void {
        $planId ??= isset($payload['plan_id']) && is_scalar($payload['plan_id']) ? (string) $payload['plan_id'] : null;
        $backupId ??= isset($payload['backup_id']) && is_scalar($payload['backup_id']) ? (string) $payload['backup_id'] : null;

        $this->db->execute(
            'INSERT INTO audit_logs (event, actor, plan_id, backup_id, payload) VALUES (?, ?, ?, ?, ?)',
            [
                mb_substr($event, 0, 80),
                mb_substr($actor, 0, 120),
                $planId === null ? null : mb_substr($planId, 0, 64),
                $backupId === null ? null : mb_substr($backupId, 0, 64),
                json_encode(
                    AuditPresenter::redact($payload),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            ],
        );
    }

    /** @return list<array{id:int,event:string,actor:string,plan_id:string,backup_id:string,created_at:string,payload:array<string, mixed>}> */
    public function latest(int $limit = 100): array
    {
        return $this->search($limit, 0, []);
    }

    /**
     * @param array{event?:string,actor?:string,category?:string,result?:string,q?:string,range?:string,from?:string,to?:string} $filters
     * @return list<array{id:int,event:string,actor:string,plan_id:string,backup_id:string,created_at:string,payload:array<string, mixed>}>
     */
    public function search(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $where = $this->where($filters);

        $rows = $this->db->fetchAll(
            'SELECT id, event, actor, plan_id, backup_id, payload, created_at FROM audit_logs'
            . $where['sql']
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $where['params'],
        );

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            $events[] = [
                'id' => (int) ($row['id'] ?? 0),
                'event' => (string) ($row['event'] ?? ''),
                'actor' => (string) ($row['actor'] ?? ''),
                'plan_id' => (string) ($row['plan_id'] ?? ''),
                'backup_id' => (string) ($row['backup_id'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return $events;
    }

    /**
     * @param array{event?:string,actor?:string,category?:string,result?:string,q?:string,range?:string,from?:string,to?:string} $filters
     */
    public function count(array $filters = []): int
    {
        $where = $this->where($filters);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM audit_logs' . $where['sql'], $where['params']);

        return (int) ($row['total'] ?? 0);
    }

    /** @return array{total:int,today:int,week:int,failed:int,actors:int} */
    public function stats(): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total,'
            . ' COALESCE(SUM(created_at >= CURDATE()), 0) AS today,'
            . ' COALESCE(SUM(created_at >= NOW() - INTERVAL 7 DAY), 0) AS week,'
            . ' COALESCE(SUM(event LIKE \'%.failed\'), 0) AS failed,'
            . ' COUNT(DISTINCT actor) AS actors'
            . ' FROM audit_logs'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'today' => (int) ($row['today'] ?? 0),
            'week' => (int) ($row['week'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'actors' => (int) ($row['actors'] ?? 0),
        ];
    }

    /** @return list<array{name:string,total:int}> */
    public function eventNames(int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->fetchAll(
            'SELECT event AS name, COUNT(*) AS total FROM audit_logs GROUP BY event ORDER BY total DESC, event LIMIT ' . $limit
        );

        /** @var list<array{name:string,total:int}> $rows */
        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'total' => (int) $row['total'],
            ],
            $rows,
        );
    }

    /** @return list<array{name:string,total:int}> */
    public function actors(int $limit = 60): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAll(
            'SELECT actor AS name, COUNT(*) AS total FROM audit_logs GROUP BY actor ORDER BY total DESC, actor LIMIT ' . $limit
        );

        /** @var list<array{name:string,total:int}> $rows */
        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'total' => (int) $row['total'],
            ],
            $rows,
        );
    }

    /** @return array<string, int> */
    public function categoryCounts(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT CASE"
            . " WHEN event LIKE 'admin.%' THEN 'admin'"
            . " WHEN event LIKE 'post.%' THEN 'post'"
            . " WHEN event LIKE 'comment.%' THEN 'comment'"
            . " WHEN event LIKE 'media.%' THEN 'media'"
            . " WHEN event LIKE 'tag.%' THEN 'tag'"
            . " WHEN event LIKE 'system.%' THEN 'system'"
            . " ELSE 'other' END AS category,"
            . ' COUNT(*) AS total FROM audit_logs GROUP BY category'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) ($row['category'] ?? 'other')] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    public function now(): string
    {
        $row = $this->db->fetchOne('SELECT NOW() AS now');

        return (string) ($row['now'] ?? '');
    }

    public function countOlderThanDays(int $days): int
    {
        $days = max(1, min(3650, $days));
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM audit_logs WHERE created_at < NOW() - INTERVAL ' . $days . ' DAY'
        );

        return (int) ($row['total'] ?? 0);
    }

    public function countBefore(string $before): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM audit_logs WHERE created_at < ?', [$before]);

        return (int) ($row['total'] ?? 0);
    }

    public function deleteBefore(string $before): int
    {
        return $this->db->execute('DELETE FROM audit_logs WHERE created_at < ?', [$before]);
    }

    /**
     * @param array{event?:string,actor?:string,category?:string,result?:string,q?:string,range?:string,from?:string,to?:string} $filters
     * @return array{sql:string,params:list<string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];

        $event = trim((string) ($filters['event'] ?? ''));
        if ($event !== '') {
            $conditions[] = 'event = ?';
            $params[] = mb_substr($event, 0, 80);
        }

        $actor = trim((string) ($filters['actor'] ?? ''));
        if ($actor !== '') {
            $conditions[] = 'actor = ?';
            $params[] = mb_substr($actor, 0, 120);
        }

        $category = (string) ($filters['category'] ?? '');
        $categorySql = $this->categorySql($category);
        if ($categorySql !== null) {
            $conditions[] = $categorySql;
        }

        $result = (string) ($filters['result'] ?? '');
        if ($result === 'failed') {
            $conditions[] = "event LIKE '%.failed'";
        } elseif ($result === 'success') {
            $conditions[] = "event NOT LIKE '%.failed'";
        }

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . mb_substr($keyword, 0, 80) . '%';
            $conditions[] = '(event LIKE ? OR actor LIKE ? OR CAST(payload AS CHAR) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $range = (string) ($filters['range'] ?? '');
        $rangeSql = match ($range) {
            'today' => 'created_at >= CURDATE()',
            '24h' => 'created_at >= NOW() - INTERVAL 1 DAY',
            '7d' => 'created_at >= NOW() - INTERVAL 7 DAY',
            '30d' => 'created_at >= NOW() - INTERVAL 30 DAY',
            '90d' => 'created_at >= NOW() - INTERVAL 90 DAY',
            default => null,
        };
        if ($rangeSql !== null) {
            $conditions[] = $rangeSql;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $conditions[] = 'created_at >= ?';
            $params[] = $from;
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $conditions[] = 'created_at <= ?';
            $params[] = $to;
        }

        return [
            'sql' => $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    private function categorySql(string $category): ?string
    {
        return match ($category) {
            'admin' => "event LIKE 'admin.%'",
            'post' => "event LIKE 'post.%'",
            'comment' => "event LIKE 'comment.%'",
            'media' => "event LIKE 'media.%'",
            'tag' => "event LIKE 'tag.%'",
            'system' => "event LIKE 'system.%'",
            'other' => "(event NOT LIKE 'admin.%' AND event NOT LIKE 'post.%' AND event NOT LIKE 'comment.%'"
                . " AND event NOT LIKE 'media.%' AND event NOT LIKE 'tag.%' AND event NOT LIKE 'system.%')",
            default => null,
        };
    }
}
