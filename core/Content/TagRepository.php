<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;
use RuntimeException;

final class TagRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array{name:string,slug:string,count:int}> */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT t.name, t.slug, COUNT(pt.post_id) AS post_count
             FROM tags t
             JOIN post_tags pt ON pt.tag_id = t.id
             JOIN posts p ON p.id = pt.post_id
             WHERE p.status = 'published' AND p.published_at <= NOW()
             GROUP BY t.id, t.name, t.slug
             ORDER BY post_count DESC, t.name"
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['post_count'],
        ], $rows);
    }

    /** @return array{name:string,slug:string}|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne('SELECT name, slug FROM tags WHERE slug = ? LIMIT 1', [$slug]);

        return $row === null ? null : ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];
    }

    /** @return list<array{id:int,name:string,slug:string,count:int}> */
    public function adminAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.name, t.slug, COUNT(pt.post_id) AS post_count
             FROM tags t LEFT JOIN post_tags pt ON pt.tag_id = t.id
             GROUP BY t.id, t.name, t.slug
             ORDER BY t.name'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['post_count'],
        ], $rows);
    }

    /** @return array{id:int,name:string,slug:string,count:int}|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT t.id, t.name, t.slug, COUNT(pt.post_id) AS post_count
             FROM tags t LEFT JOIN post_tags pt ON pt.tag_id = t.id
             WHERE t.id = ? GROUP BY t.id, t.name, t.slug LIMIT 1',
            [$id],
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['post_count'],
        ];
    }

    public function rename(int $id, string $name): bool
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
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

        $slug = $this->uniqueSlug($name);
        $this->db->execute('INSERT IGNORE INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]);

        $row = $this->db->fetchOne('SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]);
        if ($row === null) {
            throw new RuntimeException('Tag could not be created.');
        }

        return (int) $row['id'];
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
