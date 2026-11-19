<?php

declare(strict_types=1);

namespace Core\Audit;

use Core\Database\ConnectionInterface;

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
        $this->db->execute(
            'INSERT INTO audit_logs (event, actor, plan_id, backup_id, payload) VALUES (?, ?, ?, ?, ?)',
            [
                mb_substr($event, 0, 80),
                mb_substr($actor, 0, 120),
                $planId,
                $backupId,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
        );
    }

    /** @return list<array{event:string,actor:string,created_at:string,payload:array<string, mixed>}> */
    public function latest(int $limit = 100): array
    {
        return $this->search($limit, 0);
    }

    /** @return list<array{event:string,actor:string,created_at:string,payload:array<string, mixed>}> */
    public function search(int $limit = 50, int $offset = 0, ?string $event = null, ?string $actor = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = $this->where($event, $actor);

        $rows = $this->db->fetchAll(
            'SELECT event, actor, payload, created_at FROM audit_logs'
            . $where['sql']
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $where['params'],
        );

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            $events[] = [
                'event' => (string) ($row['event'] ?? ''),
                'actor' => (string) ($row['actor'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return $events;
    }

    public function count(?string $event = null, ?string $actor = null): int
    {
        $where = $this->where($event, $actor);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM audit_logs' . $where['sql'], $where['params']);

        return (int) ($row['total'] ?? 0);
    }

    /** @return list<string> */
    public function eventNames(int $limit = 60): array
    {
        $limit = max(1, min(120, $limit));
        $rows = $this->db->fetchAll(
            'SELECT event, COUNT(*) AS total FROM audit_logs GROUP BY event ORDER BY total DESC, event LIMIT ' . $limit
        );

        return array_map(static fn (array $row): string => (string) ($row['event'] ?? ''), $rows);
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

    /** @return array{sql:string,params:list<string>} */
    private function where(?string $event, ?string $actor): array
    {
        $conditions = [];
        $params = [];
        $event = $event === null ? '' : trim($event);
        $actor = $actor === null ? '' : trim($actor);
        if ($event !== '') {
            $conditions[] = 'event = ?';
            $params[] = mb_substr($event, 0, 80);
        }
        if ($actor !== '') {
            $conditions[] = 'actor = ?';
            $params[] = mb_substr($actor, 0, 120);
        }

        return [
            'sql' => $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
        ];
    }
}
