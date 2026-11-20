<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

final class PostDraftRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $postId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT post_id, title, slug, excerpt, tags_json, status, body_format, body_source,
                    body_html, base_version, draft_version, updated_at
             FROM post_drafts
             WHERE post_id = ?
             LIMIT 1',
            [$postId],
        );
        if ($row === null) {
            return null;
        }

        $tags = json_decode((string) ($row['tags_json'] ?? '[]'), true);
        $row['tags'] = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(int $postId, array $data, int $baseVersion, int $expectedDraftVersion): array
    {
        $tags = is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], 'is_string')) : [];
        $params = [
            $data['title'],
            $data['slug'],
            $data['excerpt'],
            json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $data['status'],
            $data['body_format'],
            $data['body_source'],
            $data['body_html'],
            $baseVersion,
        ];

        if ($expectedDraftVersion === 0) {
            try {
                $this->db->execute(
                    'INSERT INTO post_drafts
                     (post_id, title, slug, excerpt, tags_json, status, body_format, body_source, body_html, base_version, draft_version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [$postId, ...$params],
                );
            } catch (Throwable $exception) {
                $current = $this->find($postId);
                if ($current !== null) {
                    throw new PostDraftConflictException($current);
                }

                throw $exception;
            }
        } else {
            $affected = $this->db->execute(
                'UPDATE post_drafts
                 SET title = ?, slug = ?, excerpt = ?, tags_json = ?, status = ?, body_format = ?,
                     body_source = ?, body_html = ?, base_version = ?, draft_version = draft_version + 1
                 WHERE post_id = ? AND draft_version = ?',
                [...$params, $postId, $expectedDraftVersion],
            );
            if ($affected === 0) {
                $current = $this->find($postId);
                if ($current === null) {
                    throw new RuntimeException('工作稿不存在。');
                }
                throw new PostDraftConflictException($current);
            }
        }

        $current = $this->find($postId);
        if ($current === null) {
            throw new RuntimeException('工作稿保存后无法读取。');
        }

        return $current;
    }

    public function delete(int $postId): void
    {
        $this->db->execute('DELETE FROM post_drafts WHERE post_id = ?', [$postId]);
    }
}
