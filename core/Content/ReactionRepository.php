<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;

final class ReactionRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{likes:int,dislikes:int,mine:int} */
    public function state(int $postId, ?string $visitorHash = null): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                COALESCE(SUM(CASE WHEN value = 1 THEN 1 ELSE 0 END), 0) AS likes,
                COALESCE(SUM(CASE WHEN value = -1 THEN 1 ELSE 0 END), 0) AS dislikes
             FROM post_reactions
             WHERE post_id = ?',
            [$postId],
        );

        $mine = 0;
        if ($visitorHash !== null && $visitorHash !== '') {
            $mineRow = $this->db->fetchOne(
                'SELECT value FROM post_reactions WHERE post_id = ? AND visitor_hash = ? LIMIT 1',
                [$postId, $visitorHash],
            );
            if ($mineRow !== null) {
                $mine = (int) $mineRow['value'];
            }
        }

        return [
            'likes' => $row === null ? 0 : (int) $row['likes'],
            'dislikes' => $row === null ? 0 : (int) $row['dislikes'],
            'mine' => $mine,
        ];
    }

    /**
     * @param list<int> $postIds
     * @return array<int, array{likes:int,dislikes:int}>
     */
    public function summariesForPosts(array $postIds): array
    {
        $ids = array_values(array_unique(array_filter($postIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT post_id,
                COALESCE(SUM(CASE WHEN value = 1 THEN 1 ELSE 0 END), 0) AS likes,
                COALESCE(SUM(CASE WHEN value = -1 THEN 1 ELSE 0 END), 0) AS dislikes
             FROM post_reactions
             WHERE post_id IN (' . $placeholders . ')
             GROUP BY post_id',
            $ids,
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['post_id']] = [
                'likes' => (int) $row['likes'],
                'dislikes' => (int) $row['dislikes'],
            ];
        }

        return $result;
    }

    public function countRecentByIp(string $ipHash): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM post_reactions WHERE ip_hash = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
            [$ipHash],
        );

        return $row === null ? 0 : (int) $row['total'];
    }

    /**
     * @return array{likes:int,dislikes:int,mine:int}
     * @throws \InvalidArgumentException
     */
    public function set(int $postId, string $visitorHash, string $ipHash, int $value): array
    {
        if (!in_array($value, [-1, 0, 1], true)) {
            throw new \InvalidArgumentException('Invalid reaction value.');
        }

        if ($value === 0) {
            $this->db->execute(
                'DELETE FROM post_reactions WHERE post_id = ? AND visitor_hash = ?',
                [$postId, $visitorHash],
            );
        } else {
            $this->db->execute(
                'INSERT INTO post_reactions (post_id, visitor_hash, value, ip_hash)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = ?, ip_hash = ?, updated_at = CURRENT_TIMESTAMP',
                [$postId, $visitorHash, $value, $ipHash, $value, $ipHash],
            );
        }

        return $this->state($postId, $visitorHash);
    }
}
