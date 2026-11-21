<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;

final class CommentRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<Comment> */
    public function approvedForPost(int $postId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, post_id, parent_id, author_name, author_email, body, status, created_at, approved_at
             FROM comments
             WHERE post_id = ? AND status = 'approved'
             ORDER BY created_at ASC, id ASC",
            [$postId],
        );

        return array_map(fn (array $row): Comment => $this->map($row), $rows);
    }

    /** @return list<array{comment:Comment,replies:list<Comment>}> */
    public function threadsForPost(int $postId): array
    {
        $comments = $this->approvedForPost($postId);
        $roots = [];
        $replies = [];

        foreach ($comments as $comment) {
            if ($comment->parentId === null) {
                $roots[$comment->id] = $comment;
            } else {
                $replies[$comment->parentId][] = $comment;
            }
        }

        $threads = [];
        foreach ($roots as $root) {
            $threads[] = [
                'comment' => $root,
                'replies' => array_values(array_filter(
                    $replies[$root->id] ?? [],
                    static fn (Comment $reply): bool => $reply->status === 'approved',
                )),
            ];
        }

        return $threads;
    }

    public function countApprovedForPost(int $postId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS total FROM comments WHERE post_id = ? AND status = 'approved'",
            [$postId],
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param list<int> $postIds
     * @return array<int, array{approved:int,pending:int,spam:int}>
     */
    public function countsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $id): bool => $id > 0)));
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT post_id, status, COUNT(*) AS comment_count
             FROM comments
             WHERE post_id IN (' . $placeholders . ')
             GROUP BY post_id, status',
            $postIds,
        );

        $counts = [];
        foreach ($rows as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            if (!isset($counts[$postId])) {
                $counts[$postId] = ['approved' => 0, 'pending' => 0, 'spam' => 0];
            }
            if (array_key_exists($status, $counts[$postId])) {
                $counts[$postId][$status] = (int) ($row['comment_count'] ?? 0);
            }
        }

        return $counts;
    }

    public function findApproved(int $id, int $postId, bool $rootOnly = false): ?Comment
    {
        $row = $this->db->fetchOne(
            "SELECT id, post_id, parent_id, author_name, author_email, body, status, created_at, approved_at
             FROM comments
             WHERE id = ? AND post_id = ? AND status = 'approved'"
            . ($rootOnly ? ' AND parent_id IS NULL' : '') . '
             LIMIT 1',
            [$id, $postId],
        );

        return $row === null ? null : $this->map($row);
    }

    public function find(int $id): ?Comment
    {
        $row = $this->db->fetchOne(
            'SELECT id, post_id, parent_id, author_name, author_email, body, status, created_at, approved_at
             FROM comments WHERE id = ? LIMIT 1',
            [$id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function countRecentByIp(string $ipHash, int $minutes = 10): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM comments WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL '
            . max(1, $minutes) . ' MINUTE)',
            [$ipHash],
        );

        return (int) ($row['total'] ?? 0);
    }

    public function duplicateExists(int $postId, string $ipHash, string $body): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM comments WHERE post_id = ? AND ip_hash = ? AND body = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1',
            [$postId, $ipHash, $body],
        );

        return $row !== null;
    }

    public function create(
        int $postId,
        ?int $parentId,
        string $authorName,
        string $authorEmail,
        string $body,
        string $ipHash,
        string $userAgent,
    ): int {
        $this->db->execute(
            'INSERT INTO comments (post_id, parent_id, author_name, author_email, body, status, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$postId, $parentId, $authorName, $authorEmail, $body, 'pending', $ipHash, $userAgent],
        );
        $row = $this->db->fetchOne('SELECT LAST_INSERT_ID() AS id');

        return (int) ($row['id'] ?? 0);
    }

    /** @return list<Comment> */
    public function adminPage(?string $status, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = $this->statusWhere($status);
        $rows = $this->db->fetchAll(
            'SELECT c.id, c.post_id, c.parent_id, c.author_name, c.author_email, c.body, c.status, c.created_at, c.approved_at, p.title AS post_title, p.slug AS post_slug
             FROM comments c
             JOIN posts p ON p.id = c.post_id'
            . $where['sql']
            . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $where['params'],
        );

        return array_map(fn (array $row): Comment => $this->map($row), $rows);
    }

    public function count(?string $status): int
    {
        $where = $this->statusWhere($status);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM comments c' . $where['sql'], $where['params']);

        return (int) ($row['total'] ?? 0);
    }

    public function approve(int $id, int $adminId): bool
    {
        return $this->db->execute(
            "UPDATE comments SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?",
            [$adminId, $id],
        ) > 0;
    }

    public function markSpam(int $id): bool
    {
        return $this->db->execute(
            "UPDATE comments SET status = 'spam', approved_at = NULL, approved_by = NULL WHERE id = ?",
            [$id],
        ) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM comments WHERE id = ?', [$id]) > 0;
    }

    /** @return array{sql:string,params:list<string>} */
    private function statusWhere(?string $status): array
    {
        $status = $status === null ? '' : trim($status);
        if (!in_array($status, ['pending', 'approved', 'spam'], true)) {
            return ['sql' => '', 'params' => []];
        }

        return ['sql' => ' WHERE c.status = ?', 'params' => [$status]];
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): Comment
    {
        return new Comment(
            (int) $row['id'],
            (int) $row['post_id'],
            ($row['parent_id'] ?? null) !== null ? (int) $row['parent_id'] : null,
            (string) $row['author_name'],
            (string) ($row['author_email'] ?? ''),
            (string) $row['body'],
            (string) $row['status'],
            (string) $row['created_at'],
            ($row['approved_at'] ?? null) !== null ? (string) $row['approved_at'] : null,
            (string) ($row['post_title'] ?? ''),
            (string) ($row['post_slug'] ?? ''),
        );
    }
}
