<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;
use RuntimeException;

final class TagRepository
{
    private const ADMIN_SELECT = "SELECT t.id, t.name, t.slug, t.created_at,
        COUNT(pt.post_id) AS post_count,
        COALESCE(SUM(CASE WHEN p.status = 'published' AND p.published_at IS NOT NULL AND p.published_at <= NOW() THEN 1 ELSE 0 END), 0) AS published_count,
        MAX(p.updated_at) AS last_used_at
        FROM tags t
        LEFT JOIN post_tags pt ON pt.tag_id = t.id
        LEFT JOIN posts p ON p.id = pt.post_id";

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array{name:string,slug:string,count:int}> */
    public function all(int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));
        $rows = $this->db->fetchAll(
            "SELECT t.name, t.slug, COUNT(pt.post_id) AS post_count
             FROM tags t
             JOIN post_tags pt ON pt.tag_id = t.id
             JOIN posts p ON p.id = pt.post_id
             WHERE p.status = 'published' AND p.published_at <= NOW()
             GROUP BY t.id, t.name, t.slug
             ORDER BY post_count DESC, t.name
             LIMIT " . $limit . ' OFFSET ' . $offset
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['post_count'],
        ], $rows);
    }

    public function countAll(): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT t.id) AS total
             FROM tags t
             JOIN post_tags pt ON pt.tag_id = t.id
             JOIN posts p ON p.id = pt.post_id
             WHERE p.status = 'published' AND p.published_at <= NOW()"
        );

        return $row === null ? 0 : (int) $row['total'];
    }

    /** @return array{name:string,slug:string}|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne('SELECT name, slug FROM tags WHERE slug = ? LIMIT 1', [$slug]);

        return $row === null ? null : ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];
    }

    /**
     * 后台标签入口共用的筛选条件。
     *
     * @param array{q?:string,status?:string,sort?:string} $filters
     * @return array{where:string,params:list<string>,having:string,order:string}
     */
    private function adminQuery(array $filters): array
    {
        $where = '';
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where = 'WHERE (t.name LIKE ? OR t.slug LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }

        $status = (string) ($filters['status'] ?? 'all');
        $having = $status === 'used' ? 'HAVING post_count > 0' : ($status === 'empty' ? 'HAVING post_count = 0' : '');

        $sort = (string) ($filters['sort'] ?? 'usage');
        $order = match ($sort) {
            'name' => 'ORDER BY t.name ASC, t.id ASC',
            'recent' => 'ORDER BY last_used_at DESC, t.name ASC',
            default => 'ORDER BY post_count DESC, t.name ASC',
        };

        return ['where' => $where, 'params' => $params, 'having' => $having, 'order' => $order];
    }

    /** @return list<array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string}> */
    public function adminAll(): array
    {
        $rows = $this->db->fetchAll(
            self::ADMIN_SELECT . '
             GROUP BY t.id, t.name, t.slug, t.created_at
             ORDER BY t.name'
        );

        return $this->adminRows($rows);
    }

    /**
     * @param array{q?:string,status?:string,sort?:string} $filters
     * @return list<array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string}>
     */
    public function adminPage(int $limit = 10, int $offset = 0, array $filters = []): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));
        $query = $this->adminQuery($filters);
        $rows = $this->db->fetchAll(
            self::ADMIN_SELECT . ' ' . $query['where'] . '
             GROUP BY t.id, t.name, t.slug, t.created_at
             ' . $query['having'] . '
             ' . $query['order'] . '
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $query['params']
        );

        return $this->adminRows($rows);
    }

    /** @param array{q?:string,status?:string,sort?:string} $filters */
    public function adminCount(array $filters = []): int
    {
        $query = $this->adminQuery($filters);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM (' . self::ADMIN_SELECT . ' ' . $query['where'] . '
             GROUP BY t.id, t.name, t.slug, t.created_at ' . $query['having'] . ') counted',
            $query['params']
        );

        return $row === null ? 0 : (int) $row['total'];
    }

    /**
     * 标签管理页顶部的概览数据。
     *
     * @return array{total:int,used:int,empty:int,links:int,top_name:string,top_count:int}
     */
    public function adminSummary(): array
    {
        $tags = $this->adminAll();
        $used = 0;
        $links = 0;
        $topName = '';
        $topCount = 0;
        foreach ($tags as $tag) {
            $links += $tag['count'];
            if ($tag['count'] > 0) {
                $used++;
            }
            if ($tag['count'] > $topCount) {
                $topCount = $tag['count'];
                $topName = $tag['name'];
            }
        }

        return [
            'total' => count($tags),
            'used' => $used,
            'empty' => count($tags) - $used,
            'links' => $links,
            'top_name' => $topName,
            'top_count' => $topCount,
        ];
    }

    /** @return array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string}|null */
    public function findById(int $id): ?array
    {
        $rows = $this->db->fetchAll(
            self::ADMIN_SELECT . '
             WHERE t.id = ?
             GROUP BY t.id, t.name, t.slug, t.created_at
             LIMIT 1',
            [$id]
        );

        $normalized = $this->adminRows($rows);

        return $normalized[0] ?? null;
    }

    /**
     * 新建一个空标签。
     *
     * @return array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string}|null
     */
    public function create(string $name): ?array
    {
        $name = $this->cleanName($name);
        if ($name === null || $this->db->fetchOne('SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]) !== null) {
            return null;
        }

        $slug = $this->uniqueSlug($name);
        $inserted = $this->db->execute('INSERT IGNORE INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]);
        if ($inserted === 0) {
            return null;
        }

        $row = $this->db->fetchOne('SELECT id FROM tags WHERE name = ? AND slug = ? LIMIT 1', [$name, $slug]);
        if ($row === null) {
            return null;
        }

        return $this->findById((int) $row['id']);
    }

    public function rename(int $id, string $name): bool
    {
        $name = $this->cleanName($name);
        if ($name === null || $this->findById($id) === null) {
            return false;
        }
        $duplicate = $this->db->fetchOne('SELECT id FROM tags WHERE name = ? AND id <> ? LIMIT 1', [$name, $id]);
        if ($duplicate !== null) {
            return false;
        }
        $this->db->execute('UPDATE tags SET name = ? WHERE id = ?', [$name, $id]);

        return true;
    }

    public function merge(int $sourceId, int $targetId): bool
    {
        if ($sourceId === $targetId) {
            return false;
        }

        return (bool) $this->db->transaction(function () use ($sourceId, $targetId): bool {
            if ($this->findById($sourceId) === null || $this->findById($targetId) === null) {
                return false;
            }

            $this->db->execute(
                'INSERT IGNORE INTO post_tags (post_id, tag_id) SELECT post_id, ? FROM post_tags WHERE tag_id = ?',
                [$targetId, $sourceId],
            );
            $this->db->execute('DELETE FROM post_tags WHERE tag_id = ?', [$sourceId]);
            $this->db->execute('DELETE FROM tags WHERE id = ?', [$sourceId]);

            return true;
        });
    }

    public function delete(int $id): bool
    {
        $tag = $this->findById($id);
        if ($tag === null || $tag['count'] > 0) {
            return false;
        }
        $this->db->execute('DELETE FROM tags WHERE id = ?', [$id]);

        return true;
    }

    /**
     * 删除所有没有文章关联的标签。
     *
     * 只按关联表判断，名称相近但仍有内容的标签不会被清理。
     */
    public function cleanupEmpty(): int
    {
        return $this->db->execute(
            'DELETE t FROM tags t LEFT JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.tag_id IS NULL'
        );
    }

    /** @return list<string> */
    public function namesForPost(int $postId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.name FROM tags t INNER JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.name',
            [$postId],
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * @param list<int> $postIds
     * @return array<int, list<array{name:string,slug:string}>>
     */
    public function tagsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $id): bool => $id > 0)));
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT pt.post_id, t.name, t.slug
             FROM post_tags pt
             INNER JOIN tags t ON t.id = pt.tag_id
             WHERE pt.post_id IN (' . $placeholders . ')
             ORDER BY pt.post_id ASC, t.name ASC, t.id ASC',
            $postIds,
        );

        $tags = [];
        foreach ($rows as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            $tags[$postId][] = [
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $tags;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    public static function normalize(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
            if ($name === '' || mb_strlen($name) > 60) {
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($clean[$key])) {
                $clean[$key] = $name;
            }
        }

        return array_slice(array_values($clean), 0, 8);
    }

    /** @param list<string> $names */
    public function sync(int $postId, array $names): void
    {
        $this->db->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

        foreach (self::normalize($names) as $name) {
            $tagId = $this->firstOrCreate($name);
            $this->db->execute('INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tagId]);
        }
    }

    private function firstOrCreate(string $name): int
    {
        $row = $this->db->fetchOne('SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]);
        if ($row !== null) {
            return (int) $row['id'];
        }

        $created = $this->create($name);
        if ($created === null) {
            throw new RuntimeException('Tag could not be created.');
        }

        return $created['id'];
    }

    /** @param list<array<string, mixed>> $rows
     *  @return list<array{id:int,name:string,slug:string,count:int,published_count:int,created_at:string,last_used_at:string}>
     */
    private function adminRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['post_count'],
            'published_count' => (int) $row['published_count'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_used_at' => (string) ($row['last_used_at'] ?? ''),
        ], $rows);
    }

    private function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');

        return $name === '' || mb_strlen($name) > 60 ? null : $name;
    }

    private function uniqueSlug(string $name): string
    {
        $ascii = '';
        if (class_exists(\Transliterator::class)) {
            $transliterated = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($name);
            $ascii = is_string($transliterated) ? $transliterated : '';
        }

        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($ascii)), '-');
        if ($base === '') {
            $base = 'tag-' . substr(hash('sha256', $name), 0, 12);
        }
        $base = substr($base, 0, 72);

        $slug = $base;
        $suffix = 2;
        while ($this->db->fetchOne('SELECT id FROM tags WHERE slug = ? LIMIT 1', [$slug]) !== null) {
            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 99) {
                $slug = $base . '-' . substr(hash('sha256', $name . microtime()), 0, 8);
                break;
            }
        }

        return substr($slug, 0, 80);
    }
}
