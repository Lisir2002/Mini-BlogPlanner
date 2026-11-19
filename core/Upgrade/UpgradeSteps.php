<?php

declare(strict_types=1);

namespace Core\Upgrade;

use Core\Config\Env;
use Core\Database\MigrationLoader;
use Core\Database\Migrator;
use RuntimeException;
use Throwable;

final class UpgradeSteps
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly Migrator $migrator,
        private readonly BackupService $backups,
        private readonly CacheClearer $cache,
        private readonly SmokeChecker $smoke,
        private readonly AuditLog $audit,
        private readonly string $maintenanceFlag,
    ) {}

    /** @param array<string, mixed> $plan
     *  @param array<string, mixed> $state
     */
    public function run(string $step, array $plan, array &$state): void
    {
        switch ($step) {
            case 'preflight':
                $this->preflight($plan);
                break;
            case 'backup':
                $this->backup($state);
                break;
            case 'maintenance':
                $this->maintenance();
                break;
            case 'deploy':
                $this->deploy($plan);
                break;
            case 'migrate':
                $this->migrate($plan, $state);
                break;
            case 'warmup':
                $this->warmup($state);
                break;
            case 'switch':
                $this->switch($plan);
                break;
            case 'smoke':
                $this->smoke->check($plan);
                break;
            case 'done':
                $this->done($plan);
                break;
            default:
                throw new RuntimeException(sprintf('Unknown upgrade step: %s', $step));
        }
    }

    /** @param array<string, mixed> $plan */
    public function assertCurrentMatchesPlan(array $plan): void
    {
        $current = $this->releases->current();
        if ($current !== (string) $plan['from']['release']) {
            throw new PlanExpired('Current release changed after the plan was made.');
        }

        $actual = $this->migrator->schemaVersion();
        if ($actual !== (int) $plan['from']['schema']) {
            throw new PlanExpired('Current schema changed after the plan was made.');
        }
    }

    /** @param array<string, mixed> $plan */
    private function preflight(array $plan): void
    {
        $this->assertCurrentMatchesPlan($plan);

        if (is_file($this->maintenanceFlag)) {
            throw new RuntimeException('Maintenance mode is already active; resume or rollback first.');
        }

        $free = disk_free_space(dirname($this->maintenanceFlag));
        if ($free !== false && $free < 1024 * 1024 * 1024) {
            throw new RuntimeException('Less than 1 GB free disk space.');
        }
        if (!is_writable(dirname($this->maintenanceFlag))) {
            throw new RuntimeException('Storage directory is not writable.');
        }
    }

    /** @param array<string, mixed> $state */
    private function backup(array &$state): void
    {
        if (($state['backup_id'] ?? null) !== null && $this->backups->exists((string) $state['backup_id'])) {
            return;
        }

        $manifest = $this->backups->create('pre-upgrade');
        $state['backup_id'] = (string) $manifest['backup_id'];
        $state['updated_at'] = gmdate('c');
        $this->audit->append('system.upgrade.backup', [
            'plan_id' => $state['plan_id'],
            'backup_id' => $state['backup_id'],
        ]);
    }

    private function maintenance(): void
    {
        if (is_file($this->maintenanceFlag)) {
            return;
        }

        if (!is_dir(dirname($this->maintenanceFlag))
            && !mkdir(dirname($this->maintenanceFlag), 0775, true)
            && !is_dir(dirname($this->maintenanceFlag))
        ) {
            throw new RuntimeException('Unable to create maintenance flag directory.');
        }

        file_put_contents($this->maintenanceFlag, json_encode([
            'reason' => 'upgrade',
            'at' => gmdate('c'),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
        chmod($this->maintenanceFlag, 0640);
    }

    /** @param array<string, mixed> $plan */
    private function deploy(array $plan): void
    {
        $manifest = $this->releases->manifest((string) $plan['to']['release'], true);
        if ($manifest->app !== (string) $plan['to']['app'] || $manifest->schema !== (int) $plan['to']['schema']) {
            throw new RuntimeException('Release manifest does not match the upgrade plan.');
        }
    }

    /** @param array<string, mixed> $plan
     *  @param array<string, mixed> $state
     */
    private function migrate(array $plan, array &$state): void
    {
        $manifest = $this->releases->manifest((string) $plan['to']['release'], false);
        $loader = new MigrationLoader();
        $migrations = $loader->load($manifest->directory . '/database/migrations', 'core');
        $applied = $this->migrator->apply($migrations);

        $versions = [];
        foreach ($migrations as $migration) {
            if ($migration->version > (int) $plan['from']['schema']) {
                $versions[] = $migration->version;
            }
        }
        $state['migrations_applied'] = $versions;
        $state['schema_after'] = $this->migrator->schemaVersion();
        $state['updated_at'] = gmdate('c');
        if ($applied > 0) {
            $this->audit->append('system.upgrade.migrated', [
                'plan_id' => $state['plan_id'],
                'migrations' => $versions,
            ]);
        }
    }

    /** @param array<string, mixed> $state */
    private function warmup(array &$state): void
    {
        $state['cache_cleared'] = $this->cache->clear();
        $state['updated_at'] = gmdate('c');
    }

    /** @param array<string, mixed> $plan */
    private function switch(array $plan): void
    {
        $target = (string) $plan['to']['release'];
        if ($this->releases->current() === $target) {
            return;
        }
        $this->releases->switchTo($target);
        $this->reloadRuntime();
    }

    /**
     * PHP-FPM 和 OPcache 会缓存 current 软链的 realpath；切换后按需执行运行环境重载。
     */
    private function reloadRuntime(): void
    {
        $command = trim((string) (Env::get('RUNTIME_RELOAD_CMD', '') ?? ''));
        if ($command === '') {
            return;
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException(sprintf(
                'Runtime reload failed with exit code %d: %s',
                $code,
                implode(' ', array_slice($output, -3))
            ));
        }

        usleep(500000);
    }

    /** @param array<string, mixed> $plan */
    private function done(array $plan): void
    {
        if (is_file($this->maintenanceFlag)) {
            unlink($this->maintenanceFlag);
        }
        $this->audit->append('system.upgrade.succeeded', [
            'plan_id' => $plan['plan_id'],
            'release' => $plan['to']['release'],
        ]);
    }
}
