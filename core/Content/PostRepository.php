<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;

final class PostRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<Post> */
    public function latest(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->db->fetchAll(
            "SELECT id, slug, title, excerpt, body, published_at, updated_at, reading_minutes
             FROM posts
             WHERE status = 'published' AND published_at <= NOW()
             ORDER BY published_at DESC, id DESC
             LIMIT " . $limit
        );

        return $this->withTags(array_map(fn (array $row): Post => $this->map($row), $rows));
    }

    /** @return list<Post> */
    public function byTag(string $tagSlug, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.body, p.published_at, p.updated_at, p.reading_minutes
             FROM posts p
             JOIN post_tags pt ON pt.post_id = p.id
             JOIN tags t ON t.id = pt.tag_id
             WHERE t.slug = ? AND p.status = 'published' AND p.published_at <= NOW()
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT " . $limit,
            [$tagSlug],
        );

        $posts = array_map(fn (array $row): Post => $this->map($row), $rows);

        return $this->withTags($posts);
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

    public function findPublished(string $slug): ?Post
    {
        $row = $this->db->fetchOne(
            "SELECT id, slug, title, excerpt, body, published_at, updated_at, reading_minutes
             FROM posts
             WHERE status = 'published' AND published_at <= NOW() AND slug = ?
             LIMIT 1",
            [$slug],
        );
        if ($row === null) {
            return null;
        }

        $post = $this->map($row);
        $tags = $this->db->fetchAll(
            'SELECT t.name FROM tags t INNER JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.name',
            [$post->id],
        );

        return new Post(
            $post->id,
            $post->slug,
            $post->title,
            $post->excerpt,
            $post->body,
            $post->publishedAt,
            $post->updatedAt,
            $post->readingMinutes,
            array_map(static fn (array $tag): string => (string) $tag['name'], $tags),
        );
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
            (string) ($row['updated_at'] ?? $row['published_at']),
            (int) $row['reading_minutes'],
        );
    }
}
