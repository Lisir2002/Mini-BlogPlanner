<?php

declare(strict_types=1);

namespace Core\Upgrade;

use Core\Database\MigrationLoader;
use Core\Database\Migrator;
use Core\Version\Version;
use RuntimeException;
use Throwable;

final class UpgradeRunner
{
    private const STEPS = [
        'preflight',
        'backup',
        'maintenance',
        'deploy',
        'migrate',
        'warmup',
        'switch',
        'smoke',
        'done',
    ];

    public function __construct(
        private readonly Version $version,
        private readonly ReleaseRepository $releases,
        private readonly Migrator $migrator,
        private readonly UpgradePlanner $planner,
        private readonly UpgradeStateStore $stateStore,
        private readonly UpgradeLock $lock,
        private readonly UpgradeSteps $steps,
        private readonly AuditLog $audit,
        private readonly string $maintenanceFlag,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $schema = null;
        $pending = [];
        $pendingError = null;

        $current = $this->releases->current();
        $app = $this->version->app();
        $expectedSchema = $this->version->schema();

        try {
            $schema = $this->migrator->schemaVersion();
            if ($current !== null) {
                $manifest = $this->releases->manifest($current, false);
                $releaseVersion = Version::fromFile($manifest->directory . '/version.json');
                $app = $releaseVersion->app();
                $expectedSchema = $releaseVersion->schema();
                $loader = new MigrationLoader();
                $migrations = $loader->load($manifest->directory . '/database/migrations', 'core');
                $pending = $this->migrator->pending($migrations);
            }
        } catch (Throwable $exception) {
            $pendingError = $exception->getMessage();
        }

        return [
            'app' => $app,
            'schema' => $schema,
            'schema_expected' => $expectedSchema,
            'schema_min' => $this->version->schemaMin(),
            'current' => $this->releases->current(),
            'previous' => $this->releases->previous(),
            'maintenance' => is_file($this->maintenanceFlag),
            'pending_migrations' => count($pending),
            'pending_error' => $pendingError,
            'state' => $this->stateStore->load(),
            'releases' => $this->releases->list(),
        ];
    }

    /** @return array<string, mixed> */
    public function apply(string $planId): array
    {
        if (!$this->lock->acquire()) {
            throw new UpgradeFailure(
                'Another upgrade or restore is running.',
                3,
                'lock',
                new RuntimeException('Upgrade lock is held.'),
            );
        }

        try {
            $existing = $this->stateStore->load();
            if ($existing !== null && in_array($existing['status'] ?? '', ['running', 'failed'], true)) {
                throw new UpgradeFailure(
                    'A previous upgrade is incomplete; use resume or rollback.',
                    3,
                    'lock',
                    new RuntimeException('Upgrade state is not idle.'),
                );
            }

            $plan = $this->planner->load($planId);
            $this->assertPlan($plan, true);

            $state = $this->newState($plan);
            $this->stateStore->save($state);
            $this->audit->append('system.upgrade.started', ['plan_id' => $planId]);

            $this->execute($plan, $state, 0);

            return $state;
        } finally {
            $this->lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function resume(string $planId): array
    {
        if (!$this->lock->acquire()) {
            throw new UpgradeFailure(
                'Another upgrade or restore is running.',
                3,
                'lock',
                new RuntimeException('Upgrade lock is held.'),
            );
        }

        try {
            $state = $this->stateStore->load();
            if ($state === null
                || ($state['plan_id'] ?? null) !== $planId
                || !in_array($state['status'] ?? '', ['running', 'failed'], true)
            ) {
                throw new UpgradeFailure(
                    'No resumable upgrade matches this plan id.',
                    3,
                    'resume',
                    new RuntimeException('Upgrade state is not resumable.'),
                );
            }

            $plan = $this->planner->load($planId);
            $this->assertPlan($plan, false);

            $start = array_search($state['step'] ?? 'preflight', self::STEPS, true);
            if ($start === false) {
                throw new UpgradeFailure(
                    'Upgrade state points at an unknown step.',
                    3,
                    'resume',
                    new RuntimeException('Unknown upgrade step.'),
                );
            }

            $this->audit->append('system.upgrade.resumed', ['plan_id' => $planId, 'step' => self::STEPS[$start]]);
            $this->execute($plan, $state, (int) $start);

            return $state;
        } finally {
            $this->lock->release();
        }
    }

    /** @param array<string, mixed> $plan
     *  @param array<string, mixed> $state
     */
    private function execute(array $plan, array &$state, int $start): void
    {
        foreach (array_slice(self::STEPS, $start) as $step) {
            $state['step'] = $step;
            $state['status'] = 'running';
            $state['updated_at'] = gmdate('c');
            $this->stateStore->save($state);

            try {
                $this->steps->run($step, $plan, $state);
            } catch (Throwable $exception) {
                $state['status'] = 'failed';
                $state['error'] = $exception->getMessage();
                $state['updated_at'] = gmdate('c');
                $this->stateStore->save($state);
                $this->audit->append('system.upgrade.failed', [
                    'plan_id' => $plan['plan_id'],
                    'step' => $step,
                    'error' => $exception->getMessage(),
                ]);

                throw new UpgradeFailure(
                    $exception->getMessage(),
                    $this->exitCode($step),
                    $step,
                    $exception,
                );
            }

            $state['error'] = null;
            $state['updated_at'] = gmdate('c');
            if ($step === 'done') {
                $state['status'] = 'done';
            }
            $this->stateStore->save($state);
        }
    }

    /** @param array<string, mixed> $plan */
    private function assertPlan(array $plan, bool $strictCurrent): void
    {
        if (!isset($plan['plan_id'], $plan['to']['release'], $plan['from']['release'])) {
            throw new PlanExpired();
        }
        if ($plan['plan_id'] === '') {
            throw new PlanExpired();
        }

        $target = $this->releases->manifest((string) $plan['to']['release'], true);
        $targetVersion = Version::fromFile($target->directory . '/version.json');
        if ($targetVersion->app() !== (string) $plan['to']['app']
            || $targetVersion->schema() !== (int) $plan['to']['schema']
            || $targetVersion->schema() !== $target->schema
        ) {
            throw new PlanExpired('Target release changed after the plan was made.');
        }

        if ($strictCurrent) {
            $this->steps->assertCurrentMatchesPlan($plan);
        }
    }

    /** @param array<string, mixed> $plan
     *  @return array<string, mixed>
     */
    private function newState(array $plan): array
    {
        return [
            'plan_id' => $plan['plan_id'],
            'step' => 'preflight',
            'status' => 'running',
            'release' => $plan['to']['release'],
            'previous_release' => $plan['from']['release'],
            'backup_id' => null,
            'schema_before' => $plan['from']['schema'],
            'schema_after' => null,
            'migrations_applied' => [],
            'error' => null,
            'started_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }

    private function exitCode(string $step): int
    {
        return match ($step) {
            'preflight' => 2,
            'backup' => 4,
            'migrate' => 5,
            'smoke' => 6,
            default => 1,
        };
    }
}
