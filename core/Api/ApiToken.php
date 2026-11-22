<?php

declare(strict_types=1);

namespace Core\Api;

/**
 * 一枚 API 令牌的公开视图。
 *
 * 原始令牌只在创建时返回一次，之后只保留前缀和哈希。
 */
final class ApiToken
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $prefix,
        public readonly array $scopes,
        public readonly ?string $createdAt,
        public readonly ?string $lastUsedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $revokedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $scopes = json_decode((string) ($row['scopes_json'] ?? '[]'), true);

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['token_prefix'] ?? ''),
            is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            self::nullableString($row['created_at'] ?? null),
            self::nullableString($row['last_used_at'] ?? null),
            self::nullableString($row['expires_at'] ?? null),
            self::nullableString($row['revoked_at'] ?? null),
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && strtotime($this->expiresAt) < time();
    }

    public function isActive(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
