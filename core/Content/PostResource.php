<?php

declare(strict_types=1);

namespace Core\Content;

use Core\Config\Env;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class PostResource
{
    /** @return array<string, mixed> */
    public static function summary(Post $post): array
    {
        return [
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'published_at' => self::iso($post->publishedAt),
            'reading_minutes' => $post->readingMinutes,
            'url' => '/posts/' . $post->slug . '.html',
            'tags' => $post->tags,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Post $post): array
    {
        return self::summary($post) + ['body_html' => $post->body];
    }

    private static function iso(string $value): string
    {
        try {
            $zone = new DateTimeZone((string) Env::get('APP_TIMEZONE', 'UTC'));

            return (new DateTimeImmutable($value, $zone))->format(DATE_ATOM);
        } catch (Throwable) {
            return $value;
        }
    }
}
