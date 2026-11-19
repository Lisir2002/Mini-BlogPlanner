<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;

final class MediaRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(
        string $diskName,
        string $originalName,
        string $mime,
        int $size,
        int $width,
        int $height,
        ?int $uploaderId,
    ): int {
        $this->db->execute(
            'INSERT INTO media (disk_name, original_name, mime, size_bytes, width, height, uploader_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$diskName, $originalName, $mime, $size, $width, $height, $uploaderId],
        );
        $row = $this->db->fetchOne('SELECT LAST_INSERT_ID() AS id');

        return $row === null ? 0 : (int) $row['id'];
    }

    public function find(int $id): ?Media
    {
        $row = $this->db->fetchOne(
            'SELECT id, disk_name, original_name, mime, size_bytes, width, height, created_at FROM media WHERE id = ? LIMIT 1',
            [$id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function delete(int $id): ?Media
    {
        $media = $this->find($id);
        if ($media === null) {
            return null;
        }

        $this->db->execute('DELETE FROM media WHERE id = ?', [$id]);

        return $media;
    }

    /** @return list<string> */
    public function diskNames(): array
    {
        $rows = $this->db->fetchAll('SELECT disk_name FROM media');

        return array_map(static fn (array $row): string => (string) $row['disk_name'], $rows);
    }

    /** @return list<Media> */
    public function latest(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id, disk_name, original_name, mime, size_bytes, width, height, created_at FROM media ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );

        return array_map(fn (array $row): Media => $this->map($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): Media
    {
        return new Media(
            (int) $row['id'],
            (string) $row['disk_name'],
            (string) $row['original_name'],
            (string) $row['mime'],
            (int) $row['size_bytes'],
            (int) $row['width'],
            (int) $row['height'],
            (string) $row['created_at'],
        );
    }
}
