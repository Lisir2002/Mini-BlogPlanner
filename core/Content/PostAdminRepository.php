<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;
use RuntimeException;

final class PostAdminRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            'SELECT id, slug, title, excerpt, body_format, status, published_at, updated_at
             FROM posts
             ORDER BY updated_at DESC, id DESC
             LIMIT ' . $limit
        );
    }

    /** @return array{total:int,published:int,draft:int,archived:int,with_changes:int} */
    public function summary(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS post_count FROM posts GROUP BY status'
        );
        $counts = ['published' => 0, 'draft' => 0, 'archived' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['post_count'] ?? 0);
            }
        }
        $drafts = $this->db->fetchOne(
            "SELECT COUNT(*) AS draft_count
             FROM post_drafts d
             INNER JOIN posts p ON p.id = d.post_id
             WHERE p.status = 'published'"
        );

        return [
            'total' => $counts['published'] + $counts['draft'] + $counts['archived'],
            'published' => $counts['published'],
            'draft' => $counts['draft'],
            'archived' => $counts['archived'],
            'with_changes' => (int) ($drafts['draft_count'] ?? 0),
        ];
    }

    /**
     * @param array{status:?string,q:string,tag:string,changes:bool,sort:string} $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        [$where, $params] = $this->filterWhere($filters);

        return $this->db->fetchAll(
            'SELECT p.id, p.slug, p.title, p.excerpt, p.status, p.published_at, p.updated_at,
                    p.created_at, d.updated_at AS draft_updated_at
             FROM posts p
             LEFT JOIN post_drafts d ON d.post_id = p.id
             ' . $where . '
             ORDER BY ' . $this->orderBy($filters['sort']) . '
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
    }

    /** @param array{status:?string,q:string,tag:string,changes:bool,sort:string} $filters */
    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->filterWhere($filters);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS post_count FROM posts p ' . $where, $params);

        return (int) ($row['post_count'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, slug, title, excerpt, body, body_format, body_source, status,
                    reading_minutes, content_version, published_at, updated_at
             FROM posts
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO posts (slug, title, excerpt, body, body_format, body_source, status, published_at, author_id, reading_minutes, content_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 'published', NOW(), NULL), ?, ?, 1)",
            [
                $data['slug'],
                $data['title'],
                $data['excerpt'],
                $data['body'],
                $data['body_format'],
                $data['body_source'],
                $data['status'],
                $data['status'],
                $data['author_id'],
                $data['reading_minutes'],
            ],
        );
        $row = $this->db->fetchOne('SELECT LAST_INSERT_ID() AS id');

        return $row === null ? 0 : (int) $row['id'];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, int $expectedVersion): int
    {
        $affected = $this->db->execute(
            "UPDATE posts
             SET slug = ?, title = ?, excerpt = ?, body = ?, body_format = ?, body_source = ?, status = ?,
                 published_at = IF(? = 'published', COALESCE(published_at, NOW()), NULL),
                 author_id = ?, reading_minutes = ?, content_version = content_version + 1
             WHERE id = ? AND content_version = ?",
            [
                $data['slug'],
                $data['title'],
                $data['excerpt'],
                $data['body'],
                $data['body_format'],
                $data['body_source'],
                $data['status'],
                $data['status'],
                $data['author_id'],
                $data['reading_minutes'],
                $id,
                $expectedVersion,
            ],
        );

        if ($affected === 0) {
            $current = $this->find($id);
            if ($current === null) {
                throw new RuntimeException('文章不存在。');
            }
            throw new PostConflictException($current);
        }

        return $expectedVersion + 1;
    }

    public function archive(int $id): void
    {
        $this->archiveMany([$id]);
    }

    /** @param list<int> $ids */
    public function archiveMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return $this->db->execute(
            "UPDATE posts
             SET status = 'archived', published_at = NULL
             WHERE id IN (" . $placeholders . ") AND status <> 'archived'",
            $ids,
        );
    }

    /** @param list<int> $ids */
    public function restoreMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return $this->db->execute(
            "UPDATE posts
             SET status = 'draft', published_at = NULL
             WHERE id IN (" . $placeholders . ") AND status = 'archived'",
            $ids,
        );
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            return $this->db->fetchOne('SELECT id FROM posts WHERE slug = ? LIMIT 1', [$slug]) !== null;
        }

        return $this->db->fetchOne('SELECT id FROM posts WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $excludeId]) !== null;
    }

    /**
     * @param array{status:?string,q:string,tag:string,changes:bool,sort:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function filterWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        $status = $filters['status'];
        if (is_string($status) && in_array($status, ['published', 'draft', 'archived'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }

        $query = trim($filters['q']);
        if ($query !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $where[] = "(p.title LIKE ? ESCAPE '!' OR p.slug LIKE ? ESCAPE '!')";
            $params[] = $like;
            $params[] = $like;
        }

        $tag = trim($filters['tag']);
        if ($tag !== '') {
            $where[] = 'EXISTS (
                SELECT 1 FROM post_tags pt
                INNER JOIN tags t ON t.id = pt.tag_id
                WHERE pt.post_id = p.id AND t.slug = ?
            )';
            $params[] = $tag;
        }

        if ($filters['changes']) {
            $where[] = "p.status = 'published' AND EXISTS (SELECT 1 FROM post_drafts d WHERE d.post_id = p.id)";
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function orderBy(string $sort): string
    {
        return match ($sort) {
            'published' => 'p.published_at IS NULL ASC, p.published_at DESC, p.id DESC',
            'title' => 'p.title ASC, p.id DESC',
            default => 'p.updated_at DESC, p.id DESC',
        };
    }
}
