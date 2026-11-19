<?php

declare(strict_types=1);

use Core\Config\Env;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Upgrade\AuditLog;
use Core\Upgrade\BackupService;
use Core\Upgrade\CacheClearer;
use Core\Upgrade\PlanExpired;
use Core\Upgrade\ReleaseManifest;
use Core\Upgrade\ReleaseRepository;
use Core\Upgrade\RestoreService;
use Core\Upgrade\RollbackRunner;
use Core\Upgrade\SmokeChecker;
use Core\Upgrade\UpgradeSteps;
use Core\Upgrade\UpgradeFailure;
use Core\Upgrade\UpgradeLock;
use Core\Upgrade\UpgradePlanner;
use Core\Upgrade\UpgradeRunner;
use Core\Upgrade\UpgradeStateStore;
use Core\Version\Version;

$ctx = require dirname(__DIR__) . '/bootstrap/cli.php';

/** @var Version $version */
$version = $ctx['version'];
$json = in_array('--json', $argv, true);
$command = $argv[1] ?? 'help';

$arguments = static function (string $name, ?string $default = null) use ($argv): ?string {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen('--' . $name . '='));
        }
    }

    return $default;
};

$deployRoot = rtrim(Env::get('DEPLOY_ROOT', $ctx['root']) ?? $ctx['root'], '/');
$storageRoot = rtrim(Env::get('STORAGE_DIR', $ctx['storage']) ?? $ctx['storage'], '/');
$releases = new ReleaseRepository(
    $deployRoot . '/releases',
    $deployRoot . '/current',
    $deployRoot . '/previous',
);
$stateStore = new UpgradeStateStore($storageRoot . '/upgrade');
$lock = new UpgradeLock($storageRoot . '/upgrade/lock');
$audit = new AuditLog($storageRoot . '/logs');

$emit = static function (array $payload, int $code) use ($json): never {
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif (($payload['ok'] ?? false) !== true) {
        fwrite(STDERR, (string) ($payload['message'] ?? 'Unknown error') . PHP_EOL);
    } else {
        echo (string) ($payload['message'] ?? 'ok') . PHP_EOL;
    }
    exit($code);
};

try {
    if ($command === 'help' || in_array('--help', $argv, true)) {
        $emit([
            'ok' => true,
            'message' => implode(PHP_EOL, [
                'Usage: php bin/upgrade.php <command> [options]',
                '  status [--json]',
                '  plan --to=<release_id> [--json]',
                '  apply --plan=<plan_id> --confirm [--json]',
                '  resume --plan=<plan_id> [--json]',
                '  rollback --mode=release|down|restore [--to=<release_id>] [--backup=<id>] --confirm [--json]',
                '  verify --release=<release_id> [--json]',
            ]),
            'data' => [],
        ], 0);
    }

    if ($command === 'verify') {
        $releaseId = $arguments('release');
        if ($releaseId === null) {
            throw new RuntimeException('--release is required.');
        }
        $manifest = ReleaseManifest::fromDirectory($releases->releasesDirectory() . '/' . $releaseId);
        $manifest->verifyFiles();
        $emit([
            'ok' => true,
            'message' => sprintf('Release %s is valid.', $manifest->releaseId),
            'data' => [
                'release_id' => $manifest->releaseId,
                'app' => $manifest->app,
                'schema' => $manifest->schema,
                'files' => count($manifest->files),
            ],
        ], 0);
    }

    $db = Connection::fromEnv();
    $migrator = new Migrator($db, $version->app());
    $planner = new UpgradePlanner($releases, $migrator, $storageRoot . '/upgrade');
    $backups = new BackupService(
        $ctx['root'],
        Env::get('BACKUP_DIR', $storageRoot . '/backups') ?? $storageRoot . '/backups',
    );
    $restores = new RestoreService($ctx['root']);
    $steps = new UpgradeSteps(
        $releases,
        $migrator,
        $backups,
        new CacheClearer($storageRoot . '/cache'),
        new SmokeChecker($releases),
        $audit,
        $storageRoot . '/maintenance.flag',
    );
    $runner = new UpgradeRunner(
        $version,
        $releases,
        $migrator,
        $planner,
        $stateStore,
        $lock,
        $steps,
        $audit,
        $storageRoot . '/maintenance.flag',
    );
    $rollback = new RollbackRunner(
        $releases,
        $migrator,
        $backups,
        $restores,
        $lock,
        $audit,
        $storageRoot . '/maintenance.flag',
        $storageRoot . '/upgrade',
    );

    if ($command === 'status') {
        $data = $runner->status();
        $emit([
            'ok' => true,
            'message' => sprintf('app=%s schema=%s current=%s', $data['app'], $data['schema'] ?? 'missing', $data['current'] ?? 'none'),
            'data' => $data,
        ], 0);
    }

    if ($command === 'plan') {
        $to = $arguments('to');
        if ($to === null) {
            throw new RuntimeException('--to is required.');
        }
        $plan = $planner->plan($to);
        $emit([
            'ok' => true,
            'message' => sprintf('Plan %s: %s -> %s', $plan['plan_id'], $plan['from']['app'], $plan['to']['app']),
            'data' => $plan,
        ], 0);
    }

    if ($command === 'apply') {
        $planId = $arguments('plan');
        if ($planId === null) {
            throw new RuntimeException('--plan is required.');
        }
        if (!in_array('--confirm', $argv, true)) {
            throw new UpgradeFailure('apply requires --confirm.', 1, 'apply', new RuntimeException('Confirmation missing.'));
        }
        $state = $runner->apply($planId);
        $emit([
            'ok' => true,
            'message' => sprintf('Upgrade %s completed.', $planId),
            'data' => $state,
        ], 0);
    }

    if ($command === 'resume') {
        $planId = $arguments('plan');
        if ($planId === null) {
            throw new RuntimeException('--plan is required.');
        }
        $state = $runner->resume($planId);
        $emit([
            'ok' => true,
            'message' => sprintf('Upgrade %s resumed to %s.', $planId, $state['status']),
            'data' => $state,
        ], 0);
    }

    if ($command === 'rollback') {
        $mode = $arguments('mode');
        if ($mode === null) {
            throw new RuntimeException('--mode is required.');
        }
        if (!in_array('--confirm', $argv, true)) {
            throw new UpgradeFailure('rollback requires --confirm.', 1, 'rollback', new RuntimeException('Confirmation missing.'));
        }
        $result = $rollback->rollback($mode, $arguments('to'), $arguments('backup'));
        $emit([
            'ok' => true,
            'message' => sprintf('Rollback (%s) completed.', $mode),
            'data' => $result['data'],
        ], 0);
    }

    throw new RuntimeException(sprintf('Unknown command: %s', $command));
} catch (UpgradeFailure $exception) {
    $emit([
        'ok' => false,
        'code' => $exception->exitCode,
        'message' => $exception->getMessage(),
        'data' => ['step' => $exception->step],
    ], $exception->exitCode);
} catch (PlanExpired $exception) {
    $emit([
        'ok' => false,
        'code' => 9,
        'message' => $exception->getMessage(),
        'data' => [],
    ], 9);
} catch (Throwable $exception) {
    if (Env::bool('APP_DEBUG')) {
        fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    }
    $emit([
        'ok' => false,
        'code' => 1,
        'message' => $exception->getMessage(),
        'data' => ['exception' => $exception::class],
    ], 1);
}
