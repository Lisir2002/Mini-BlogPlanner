<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Database\ConnectionInterface;

final class MediaRepository
{
    /** @var array<string, string> */
    public const TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** @var list<string> */
    public const SORTS = ['newest', 'oldest', 'largest', 'name'];

    /** @var array<string, string> */
    public const SHAPES = [
        'landscape' => '横图',
        'portrait' => '竖图',
        'square' => '方图',
    ];

    /** @var array<string, string> */
    public const WEIGHTS = [
        'small' => '100KB 以下',
        'medium' => '100KB–1MB',
        'large' => '1MB 以上',
    ];

    /** @var array<string, string> */
    public const PERIODS = [
        'today' => '24 小时内',
        'week' => '7 天内',
        'month' => '30 天内',
    ];

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

    /** @param list<int> $ids
     *  @return list<Media> */
    public function findMany(array $ids): array
    {
        $ids = $this->cleanIds($ids);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT id, disk_name, original_name, mime, size_bytes, width, height, created_at FROM media WHERE id IN (' . $placeholders . ') ORDER BY id ASC',
            $ids,
        );

        return array_map(fn (array $row): Media => $this->map($row), $rows);
    }

    public function rename(int $id, string $name): ?Media
    {
        $this->db->execute('UPDATE media SET original_name = ? WHERE id = ?', [$name, $id]);

        return $this->find($id);
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

    /** @param list<int> $ids
     *  @return list<Media> */
    public function deleteMany(array $ids): array
    {
        $ids = $this->cleanIds($ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->transaction(function () use ($ids): array {
            $deleted = [];
            foreach ($ids as $id) {
                $media = $this->delete($id);
                if ($media !== null) {
                    $deleted[] = $media;
                }
            }

            return $deleted;
        });
    }

    /** @return list<string> */
    public function diskNames(): array
    {
        $rows = $this->db->fetchAll('SELECT disk_name FROM media');

        return array_map(static fn (array $row): string => (string) $row['disk_name'], $rows);
    }

    /** @return list<Media> */
    public function latest(int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));
        $rows = $this->db->fetchAll(
            'SELECT id, disk_name, original_name, mime, size_bytes, width, height, created_at FROM media ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );

        return array_map(fn (array $row): Media => $this->map($row), $rows);
    }

    /**
     * @param array{q?:string,type?:string,shape?:string,weight?:string,period?:string,sort?:string} $filters
     * @return list<Media>
     */
    public function adminPage(array $filters, int $limit = 10, int $offset = 0): array
    {
        $filters = self::normalizeFilters($filters);
        [$where, $params] = $this->where($filters);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));
        $order = match ($filters['sort']) {
            'oldest' => 'created_at ASC, id ASC',
            'largest' => 'size_bytes DESC, id DESC',
            'name' => 'original_name ASC, id DESC',
            default => 'created_at DESC, id DESC',
        };
        $rows = $this->db->fetchAll(
            'SELECT id, disk_name, original_name, mime, size_bytes, width, height, created_at FROM media'
            . $where
            . ' ORDER BY ' . $order
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        return array_map(fn (array $row): Media => $this->map($row), $rows);
    }

    /** @param array{q?:string,type?:string,shape?:string,weight?:string,period?:string,sort?:string} $filters */
    public function adminCount(array $filters): int
    {
        $filters = self::normalizeFilters($filters);
        [$where, $params] = $this->where($filters);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM media' . $where, $params);

        return $row === null ? 0 : (int) $row['total'];
    }

    /** @return array{total:int,bytes:int,latest_at:?string,types:array<string,int>} */
    public function summary(): array
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(size_bytes), 0) AS bytes, MAX(created_at) AS latest_at,"
            . " COALESCE(SUM(mime = 'image/jpeg'), 0) AS jpg,"
            . " COALESCE(SUM(mime = 'image/png'), 0) AS png,"
            . " COALESCE(SUM(mime = 'image/webp'), 0) AS webp,"
            . " COALESCE(SUM(mime = 'image/gif'), 0) AS gif FROM media"
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'latest_at' => isset($row['latest_at']) ? (string) $row['latest_at'] : null,
            'types' => [
                'jpg' => (int) ($row['jpg'] ?? 0),
                'png' => (int) ($row['png'] ?? 0),
                'webp' => (int) ($row['webp'] ?? 0),
                'gif' => (int) ($row['gif'] ?? 0),
            ],
        ];
    }

    public function count(): int
    {
        return $this->adminCount([]);
    }

    /** @param array{q?:string,type?:string,shape?:string,weight?:string,period?:string,sort?:string} $filters
     *  @return array{q:string,type:string,shape:string,weight:string,period:string,sort:string} */
    public static function normalizeFilters(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $q = mb_substr($q, 0, 80);
        $type = (string) ($filters['type'] ?? '');
        $type = array_key_exists($type, self::TYPES) ? $type : '';
        $shape = (string) ($filters['shape'] ?? '');
        $shape = array_key_exists($shape, self::SHAPES) ? $shape : '';
        $weight = (string) ($filters['weight'] ?? '');
        $weight = array_key_exists($weight, self::WEIGHTS) ? $weight : '';
        $period = (string) ($filters['period'] ?? '');
        $period = array_key_exists($period, self::PERIODS) ? $period : '';
        $sort = (string) ($filters['sort'] ?? 'newest');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'newest';

        return ['q' => $q, 'type' => $type, 'shape' => $shape, 'weight' => $weight, 'period' => $period, 'sort' => $sort];
    }

    /** @param array{q:string,type:string,shape:string,weight:string,period:string,sort:string} $filters
     *  @return array{0:string,1:list<string>} */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = '(original_name LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        if ($filters['type'] !== '') {
            $where[] = 'mime = ?';
            $params[] = self::TYPES[$filters['type']];
        }
        if ($filters['shape'] !== '') {
            $where[] = match ($filters['shape']) {
                'landscape' => 'width > height * 1.05',
                'portrait' => 'height > width * 1.05',
                default => 'width >= height * 0.95 AND width <= height * 1.05',
            };
        }
        if ($filters['weight'] !== '') {
            $where[] = match ($filters['weight']) {
                'small' => 'size_bytes < 102400',
                'medium' => 'size_bytes >= 102400 AND size_bytes < 1048576',
                default => 'size_bytes >= 1048576',
            };
        }
        if ($filters['period'] !== '') {
            $where[] = match ($filters['period']) {
                'today' => 'created_at >= NOW() - INTERVAL 1 DAY',
                'week' => 'created_at >= NOW() - INTERVAL 7 DAY',
                default => 'created_at >= NOW() - INTERVAL 30 DAY',
            };
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /** @param list<int> $ids
     *  @return list<int> */
    private function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
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
