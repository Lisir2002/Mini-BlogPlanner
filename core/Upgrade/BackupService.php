<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class BackupService
{
    public function __construct(
        private readonly string $root,
        private readonly string $backupDirectory,
        private readonly CommandRunner $runner = new CommandRunner(),
    ) {}

    /** @return array<string, mixed> */
    public function create(string $reason = 'pre-upgrade'): array
    {
        $result = $this->runner->run([
            '/bin/bash',
            $this->root . '/bin/backup.sh',
            '--reason=' . $reason,
            '--json',
        ], $this->root, [
            'PHP_BIN' => PHP_BINARY,
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ]);

        if ($result['code'] !== 0) {
            throw new RuntimeException('Backup failed: ' . trim($result['stderr']));
        }

        $data = json_decode(trim($result['stdout']), true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true || !is_array($data['data'] ?? null)) {
            throw new RuntimeException('Backup returned an invalid result.');
        }

        /** @var array<string, mixed> $manifest */
        $manifest = $data['data'];
        if (!isset($manifest['backup_id']) || !is_string($manifest['backup_id'])) {
            throw new RuntimeException('Backup result is missing backup_id.');
        }
        if (!is_file($this->backupDirectory . '/' . $manifest['backup_id'] . '/manifest.json')) {
            throw new RuntimeException('Backup manifest was not created.');
        }

        return $manifest;
    }

    public function exists(string $backupId): bool
    {
        return !str_contains($backupId, '/')
            && !str_contains($backupId, '..')
            && is_file($this->backupDirectory . '/' . $backupId . '/manifest.json');
    }
}
