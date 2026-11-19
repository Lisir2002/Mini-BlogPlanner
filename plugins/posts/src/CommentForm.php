<?php

declare(strict_types=1);

namespace Plugin\Posts;

final class CommentForm
{
    private const KEY = 'comment_form_at';
    private const MIN_SECONDS = 3;
    private const MAX_SECONDS = 7200;

    public static function issue(): string
    {
        $at = time();
        $_SESSION[self::KEY] = $at;

        return (string) $at;
    }

    public static function validate(?string $raw): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;
        $at = filter_var($raw, FILTER_VALIDATE_INT);
        if (!is_int($expected) || !is_int($at) || $at !== $expected) {
            return false;
        }

        $elapsed = time() - $at;

        return $elapsed >= self::MIN_SECONDS && $elapsed <= self::MAX_SECONDS;
    }

    public static function consume(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
