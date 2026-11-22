<?php

declare(strict_types=1);

namespace Core\Api;

use Core\Database\ConnectionInterface;

/**
 * API 令牌的签发、查询与撤销。
 *
 * 数据库只保存 SHA-256 哈希，原始令牌不会落盘，也不会写进审计 payload。
 */
final class ApiTokenRepository
{
    public const TOKEN_PREFIX = 'mbp_';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param list<string> $scopes
     * @return array{id:int,prefix:string,token:string}
     */
    public function issue(string $name, array $scopes, ?string $expiresAt, int $adminId): array
    {
        $raw = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $prefix = substr($raw, 0, 12);
        $hash = hash('sha256', $raw);

        $this->db->execute(
            'INSERT INTO api_tokens (name, token_prefix, token_hash, scopes_json, created_by, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                mb_substr(trim($name), 0, 80),
                $prefix,
                $hash,
                json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $adminId,
                $expiresAt,
            ],
        );
        $row = $this->db->fetchOne('SELECT LAST_INSERT_ID() AS id');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'prefix' => $prefix,
            'token' => $raw,
        ];
    }

    /** @return list<ApiToken> */
    public function page(int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $rows = $this->db->fetchAll(
            'SELECT id, name, token_prefix, scopes_json, created_at, last_used_at, expires_at, revoked_at
             FROM api_tokens
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );

        return array_map(static fn (array $row): ApiToken => ApiToken::fromRow($row), $rows);
    }

    public function count(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM api_tokens');

        return (int) ($row['total'] ?? 0);
    }

    public function activeCount(): int
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, token_prefix, scopes_json, created_at, last_used_at, expires_at, revoked_at FROM api_tokens'
        );
        $active = 0;
        foreach ($rows as $row) {
            if (ApiToken::fromRow($row)->isActive()) {
                $active++;
            }
        }

        return $active;
    }

    public function findActiveByRaw(string $raw): ?ApiToken
    {
        if (!str_starts_with($raw, self::TOKEN_PREFIX) || strlen($raw) < 40) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id, name, token_prefix, scopes_json, created_at, last_used_at, expires_at, revoked_at
             FROM api_tokens
             WHERE token_hash = ?
             LIMIT 1',
            [hash('sha256', $raw)],
        );
        if ($row === null) {
            return null;
        }

        $token = ApiToken::fromRow($row);

        return $token->isActive() ? $token : null;
    }

    public function revoke(int $id): bool
    {
        return $this->db->execute(
            'UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL',
            [$id],
        ) > 0;
    }

    public function touch(int $id): void
    {
        $this->db->execute('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$id]);
    }
}
