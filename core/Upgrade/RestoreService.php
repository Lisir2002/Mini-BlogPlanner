<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class RestoreService
{
    public function __construct(
        private readonly string $root,
        private readonly CommandRunner $runner = new CommandRunner(),
    ) {}

    public function restore(string $backupId): void
    {
        $result = $this->runner->run([
            PHP_BINARY,
            $this->root . '/bin/restore.php',
            '--backup=' . $backupId,
            '--confirm',
            '--json',
        ], $this->root);

        if ($result['code'] !== 0) {
            throw new RuntimeException('Restore failed: ' . trim($result['stderr']));
        }

        $data = json_decode(trim($result['stdout']), true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new RuntimeException('Restore returned an invalid result.');
        }
    }
}
