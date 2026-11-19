<?php

declare(strict_types=1);

namespace Core\Auth;

final class Auth
{
    private const SESSION_ID = 'admin_id';
    private const SESSION_SEEN = 'admin_seen';
    private const IDLE_SECONDS = 7200;

    public function __construct(private readonly AdminRepository $repository) {}

    public function user(): ?Admin
    {
        $id = $_SESSION[self::SESSION_ID] ?? null;
        if (!is_int($id)) {
            return null;
        }

        $seen = $_SESSION[self::SESSION_SEEN] ?? 0;
        if (!is_int($seen) || $seen < time() - self::IDLE_SECONDS) {
            $this->logout();

            return null;
        }

        $admin = $this->repository->findById($id);
        if ($admin === null) {
            $this->logout();

            return null;
        }
        $_SESSION[self::SESSION_SEEN] = time();

        return $admin;
    }

    public function login(string $username, string $password): bool
    {
        $admin = $this->repository->findActiveByUsername($username);
        if ($admin === null || $admin->passwordHash === null) {
            return false;
        }
        if (!password_verify($password, $admin->passwordHash)) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_ID] = $admin->id;
        $_SESSION[self::SESSION_SEEN] = time();
        $this->repository->touch($admin->id);

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_ID], $_SESSION[self::SESSION_SEEN]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }
}
