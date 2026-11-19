<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Database\ConnectionInterface;

final class AdminRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function findActiveByUsername(string $username): ?Admin
    {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, role, password_hash
             FROM admins
             WHERE username = ? AND status = 'active'
             LIMIT 1",
            [$username],
        );
        if ($row === null) {
            return null;
        }

        return new Admin(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['role'],
            (string) $row['password_hash'],
        );
    }

    public function findById(int $id): ?Admin
    {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, role
             FROM admins
             WHERE id = ? AND status = 'active'
             LIMIT 1",
            [$id],
        );
        if ($row === null) {
            return null;
        }

        return new Admin((int) $row['id'], (string) $row['username'], (string) $row['email'], (string) $row['role']);
    }

    public function touch(int $id): void
    {
        $this->db->execute('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$id]);
    }
}
