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
        $this->db->execute("UPDATE posts SET status = 'archived', published_at = NULL WHERE id = ?", [$id]);
    }
}
