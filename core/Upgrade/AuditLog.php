<?php

declare(strict_types=1);

namespace Core\Upgrade;

final class AuditLog
{
    public function __construct(private readonly string $directory) {}

    /** @param array<string, mixed> $data */
    public function append(string $event, array $data): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $line = json_encode([
            'event' => $event,
            'at' => gmdate('c'),
            'actor' => get_current_user() ?: 'cli',
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents(
            rtrim($this->directory, '/') . '/upgrade.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
