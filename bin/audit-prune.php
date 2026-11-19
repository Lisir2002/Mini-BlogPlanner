<?php

declare(strict_types=1);

use Core\Audit\AuditRepository;
use Core\Config\Env;
use Core\Database\Connection;

$ctx = require dirname(__DIR__) . '/bootstrap/cli.php';
$json = in_array('--json', $argv, true);
$confirm = in_array('--confirm', $argv, true);
$days = 90;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--days=')) {
        $days = (int) substr($argument, 7);
    }
}
$days = max(7, min(3650, $days));
$cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

try {
    $db = Connection::fromEnv();
    $repository = new AuditRepository($db);
    $count = $repository->countBefore($cutoff);
    $deleted = 0;
    if ($confirm && $count > 0) {
        $deleted = $repository->deleteBefore($cutoff);
        $repository->append('system.audit.pruned', 'cli', [
            'before' => $cutoff,
            'days' => $days,
            'deleted' => $deleted,
        ]);
    }
} catch (Throwable $exception) {
    $payload = ['ok' => false, 'message' => '审计清理失败。', 'data' => []];
    if ($json) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        fwrite(STDERR, $payload['message'] . PHP_EOL);
    }
    exit(1);
}

$payload = [
    'ok' => true,
    'message' => $confirm
        ? sprintf('已删除 %d 条早于 %s 的审计记录。', $deleted, $cutoff)
        : sprintf('将删除 %d 条早于 %s 的审计记录；加 --confirm 执行。', $count, $cutoff),
    'data' => [
        'cutoff' => $cutoff,
        'days' => $days,
        'matched' => $count,
        'deleted' => $deleted,
        'dry_run' => !$confirm,
    ],
];
if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    echo $payload['message'] . PHP_EOL;
}
exit(0);
