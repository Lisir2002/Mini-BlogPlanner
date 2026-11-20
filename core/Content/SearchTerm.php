<?php

declare(strict_types=1);

namespace Core\Content;

final class SearchTerm
{
    public const MAX_LENGTH = 60;

    public static function normalize(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        $text = strip_tags($raw);
        $text = preg_replace('/[\s\x{3000}]+/u', ' ', trim($text));
        if (!is_string($text)) {
            return '';
        }

        return mb_substr(trim($text), 0, self::MAX_LENGTH, 'UTF-8');
    }

    public static function isShort(string $query): bool
    {
        return mb_strlen($query, 'UTF-8') < 2;
    }
}
