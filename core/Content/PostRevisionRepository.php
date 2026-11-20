<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;

final class PostRevisionRepository
{
    public const RETENTION = 50;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param array<string, mixed> $data
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    public function record(int $postId, int $version, array $data, array $tags, ?int $authorId = null): array
    {
        $bodyHtml = (string) ($data['body_html'] ?? $data['body'] ?? '');
        $bodySource = $data['body_source'] ?? null;
        $bodySource = $bodySource === null || $bodySource === '' ? null : (string) $bodySource;
        $status = in_array($data['status'] ?? null, ['draft', 'published', 'archived'], true)
            ? (string) $data['status']
            : 'draft';

        $this->db->execute(
            "INSERT INTO post_revisions
             (post_id, version, title, slug, excerpt, tags_json, status, body_format, body_source, body_html,
              reading_minutes, author_id, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'published', NOW(), NULL))",
            [
                $postId,
                $version,
                (string) ($data['title'] ?? ''),
                (string) ($data['slug'] ?? ''),
                (string) ($data['excerpt'] ?? ''),
                json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $status,
                (string) ($data['body_format'] ?? 'html'),
                $bodySource,
                $bodyHtml,
                max(1, (int) ($data['reading_minutes'] ?? 1)),
                $authorId,
                $status,
            ],
        );

        $this->prune($postId);

        $revision = $this->find($postId, $version);
        if ($revision === null) {
            throw new \RuntimeException('版本快照写入后无法读取。');
        }

        return $revision;
    }

    /** @return list<array<string, mixed>> */
    public function listForPost(int $postId, int $limit = self::RETENTION): array
    {
        $limit = max(1, min(self::RETENTION, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id, post_id, version, title, slug, excerpt, status, body_format, body_source,
                    CHAR_LENGTH(body_html) AS body_size, reading_minutes, author_id, published_at, created_at
             FROM post_revisions
             WHERE post_id = ?
             ORDER BY version DESC
             LIMIT ' . $limit,
            [$postId],
        );

        return array_map(static function (array $row): array {
            $row['tags'] = [];
            $row['body_size'] = (int) ($row['body_size'] ?? 0);

            return $row;
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $postId, int $version): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, post_id, version, title, slug, excerpt, tags_json, status, body_format, body_source,
                    body_html, reading_minutes, author_id, published_at, created_at
             FROM post_revisions
             WHERE post_id = ? AND version = ?
             LIMIT 1',
            [$postId, $version],
        );
        if ($row === null) {
            return null;
        }

        $tags = json_decode((string) ($row['tags_json'] ?? '[]'), true);
        $row['tags'] = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];

        return $row;
    }

    public function countForPost(int $postId): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM post_revisions WHERE post_id = ?', [$postId]);

        return (int) ($row['c'] ?? 0);
    }

    private function prune(int $postId): void
    {
        $row = $this->db->fetchOne(
            'SELECT version FROM post_revisions WHERE post_id = ? ORDER BY version DESC LIMIT 1 OFFSET ' . (self::RETENTION - 1),
            [$postId],
        );
        if ($row === null) {
            return;
        }

        $this->db->execute(
            'DELETE FROM post_revisions WHERE post_id = ? AND version < ?',
            [$postId, (int) $row['version']],
        );
    }
}
