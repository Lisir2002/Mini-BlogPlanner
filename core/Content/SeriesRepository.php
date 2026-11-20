<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;
use RuntimeException;

final class SeriesRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array{name:string,slug:string,description:string,count:int}> */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT s.name, s.slug, s.description, COUNT(p.id) AS post_count
             FROM series s
             JOIN posts p ON p.series_id = s.id AND p.status = 'published' AND p.published_at <= NOW()
             GROUP BY s.id, s.name, s.slug, s.description
             ORDER BY MAX(p.published_at) DESC, s.name"
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) $row['description'],
            'count' => (int) $row['post_count'],
        ], $rows);
    }

    /** @return array{id:int,name:string,slug:string,description:string,count:int}|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT s.id, s.name, s.slug, s.description, COUNT(p.id) AS post_count
             FROM series s
             LEFT JOIN posts p ON p.series_id = s.id AND p.status = 'published' AND p.published_at <= NOW()
             WHERE s.slug = ?
             GROUP BY s.id, s.name, s.slug, s.description
             LIMIT 1",
            [$slug],
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) $row['description'],
            'count' => (int) $row['post_count'],
        ];
    }

    /** @return list<Post> */
    public function postsForSeries(int $seriesId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.body, p.published_at, p.updated_at, p.reading_minutes
             FROM posts p
             JOIN series s ON s.id = p.series_id
             WHERE s.id = ? AND p.status = 'published' AND p.published_at <= NOW()
             ORDER BY p.published_at ASC, p.id ASC
             LIMIT " . $limit,
            [$seriesId],
        );

        return $this->withTags(array_map(fn (array $row): Post => $this->map($row), $rows));
    }

    /** @return array{id:int,name:string,slug:string}|null */
    public function forPost(int $postId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT s.id, s.name, s.slug FROM series s JOIN posts p ON p.series_id = s.id WHERE p.id = ? LIMIT 1',
            [$postId],
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
        ];
    }

    /** @return list<array{id:int,name:string,slug:string,description:string,count:int}> */
    public function adminAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.id, s.name, s.slug, s.description, COUNT(p.id) AS post_count
             FROM series s
             LEFT JOIN posts p ON p.series_id = s.id
             GROUP BY s.id, s.name, s.slug, s.description
             ORDER BY s.name'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) $row['description'],
            'count' => (int) $row['post_count'],
        ], $rows);
    }

    /** @return array{id:int,name:string,slug:string,description:string,count:int}|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT s.id, s.name, s.slug, s.description, COUNT(p.id) AS post_count
             FROM series s
             LEFT JOIN posts p ON p.series_id = s.id
             WHERE s.id = ?
             GROUP BY s.id, s.name, s.slug, s.description
             LIMIT 1',
            [$id],
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) $row['description'],
            'count' => (int) $row['post_count'],
        ];
    }

    public function exists(int $id): bool
    {
        return $this->db->fetchOne('SELECT id FROM series WHERE id = ? LIMIT 1', [$id]) !== null;
    }

    public function create(string $name, string $slug, string $description): int
    {
        $name = $this->cleanName($name);
        $description = $this->cleanDescription($description);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('系列名必须为 1–120 个字符。');
        }
        if (mb_strlen($description) > 500) {
            throw new RuntimeException('系列说明不能超过 500 个字符。');
        }
        if ($this->db->fetchOne('SELECT id FROM series WHERE name = ? LIMIT 1', [$name]) !== null) {
            throw new RuntimeException('系列名已存在。');
        }

        $slug = trim($slug);
        if ($slug === '') {
            $slug = $this->uniqueSlug($name);
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80) {
            throw new RuntimeException('系列 slug 只能是小写字母、数字和单个短横线。');
        }
        if ($this->db->fetchOne('SELECT id FROM series WHERE slug = ? LIMIT 1', [$slug]) !== null) {
            throw new RuntimeException('系列 slug 已存在。');
        }

        $this->db->execute(
            'INSERT INTO series (name, slug, description) VALUES (?, ?, ?)',
            [$name, $slug, $description],
        );
        $row = $this->db->fetchOne('SELECT LAST_INSERT_ID() AS id');

        return $row === null ? 0 : (int) $row['id'];
    }

    public function update(int $id, string $name, string $description): bool
    {
        $name = $this->cleanName($name);
        $description = $this->cleanDescription($description);
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($description) > 500) {
            return false;
        }
        if ($this->db->fetchOne('SELECT id FROM series WHERE name = ? AND id <> ? LIMIT 1', [$name, $id]) !== null) {
            return false;
        }
        if ($this->findById($id) === null) {
            return false;
        }

        $this->db->execute('UPDATE series SET name = ?, description = ? WHERE id = ?', [$name, $description, $id]);

        return true;
    }

    public function delete(int $id): bool
    {
        $series = $this->findById($id);
        if ($series === null || $series['count'] > 0) {
            return false;
        }

        $this->db->execute('DELETE FROM series WHERE id = ?', [$id]);

        return true;
    }

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($name)));
    }

    private function cleanDescription(string $description): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));
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
            $base = 'series-' . substr(hash('sha256', $name), 0, 12);
        }
        $base = substr($base, 0, 70);

        $slug = $base;
        $suffix = 2;
        while ($this->db->fetchOne('SELECT id FROM series WHERE slug = ? LIMIT 1', [$slug]) !== null) {
            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 99) {
                $slug = $base . '-' . substr(hash('sha256', $name . microtime()), 0, 8);
                break;
            }
        }

        return substr($slug, 0, 80);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): Post
    {
        return new Post(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['title'],
            (string) $row['excerpt'],
            (string) $row['body'],
            (string) $row['published_at'],
            (string) $row['updated_at'],
            (int) $row['reading_minutes'],
        );
    }

    /** @param list<Post> $posts
     *  @return list<Post>
     */
    private function withTags(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $ids = array_map(static fn (Post $post): int => $post->id, $posts);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tagRows = $this->db->fetchAll(
            'SELECT pt.post_id, t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN (' . $placeholders . ') ORDER BY t.name',
            $ids,
        );
        $byPost = [];
        foreach ($tagRows as $tagRow) {
            $byPost[(int) $tagRow['post_id']][] = (string) $tagRow['name'];
        }

        return array_map(static fn (Post $post): Post => new Post(
            $post->id,
            $post->slug,
            $post->title,
            $post->excerpt,
            $post->body,
            $post->publishedAt,
            $post->updatedAt,
            $post->readingMinutes,
            $byPost[$post->id] ?? [],
        ), $posts);
    }
}
