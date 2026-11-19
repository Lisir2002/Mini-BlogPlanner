<?php

declare(strict_types=1);

namespace Core\Upgrade;

use Core\Database\Migration;
use Core\Database\MigrationLoader;
use Core\Database\Migrator;
use Core\Version\Version;
use RuntimeException;

final class UpgradePlanner
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly Migrator $migrator,
        private readonly string $upgradeDirectory,
    ) {}

    /** @return array<string, mixed> */
    public function plan(string $toRelease): array
    {
        $current = $this->releases->current();
        if ($current === null) {
            throw new RuntimeException('No current release is linked; install first.');
        }

        $currentRelease = $this->releases->manifest($current, false);
        $currentVersion = Version::fromFile($currentRelease->directory . '/version.json');
        $target = $this->releases->manifest($toRelease, true);
        $targetVersion = $this->targetVersion($target);
        if ($targetVersion->app() !== $target->app || $targetVersion->schema() !== $target->schema) {
            throw new RuntimeException('Release manifest and version.json disagree on app/schema.');
        }
        $currentSchema = $this->migrator->schemaVersion();
        if ($currentSchema === null) {
            throw new RuntimeException('schema_meta is missing; run the installer first.');
        }
        if (version_compare($target->app, $currentVersion->app(), '<')) {
            throw new RuntimeException('Upgrade target cannot be older than the current application version.');
        }
        if ($target->schema < $currentSchema) {
            throw new RuntimeException('Upgrade target cannot lower the database schema; use rollback.');
        }

        $loader = new MigrationLoader();
        $targetMigrations = $loader->load($target->directory . '/database/migrations', 'core');
        if ($targetMigrations === [] && $targetVersion->schema() !== $currentSchema) {
            throw new RuntimeException('Target release has no core migrations but declares a new schema.');
        }
        if ($targetMigrations !== []) {
            $maxCore = max(array_map(static fn (Migration $migration): int => $migration->version, $targetMigrations));
            if ($maxCore !== $targetVersion->schema()) {
                throw new RuntimeException('Target core migrations do not match version.json schema.');
            }
        }
        $pending = $this->migrator->pending($targetMigrations);

        $pointOfNoReturn = null;
        foreach ($pending as $migration) {
            if ($migration->irreversible) {
                $pointOfNoReturn = [
                    'scope' => $migration->scope,
                    'version' => $migration->version,
                    'name' => $migration->name,
                ];
                break;
            }
        }

        $schemaChange = $target->schema !== $currentSchema || $pending !== [];
        $rollbackModes = ['restore'];
        if (!$schemaChange) {
            $rollbackModes = ['release'];
        } elseif ($pointOfNoReturn === null) {
            $rollbackModes = ['down', 'restore'];
        }

        $warnings = [];
        if ($schemaChange) {
            $warnings[] = '升级期间站点进入维护模式';
        }
        if ($pointOfNoReturn !== null) {
            $warnings[] = '本次迁移包含不可回退点，回退只能恢复备份';
        }
        if ($this->releases->previous() === null) {
            $warnings[] = '当前没有 previous release，release 回退不可用';
        }

        $plan = [
            'ok' => true,
            'plan_id' => $this->makePlanId($current, $target, $currentVersion->app(), $currentSchema),
            'from' => [
                'app' => $currentVersion->app(),
                'schema' => $currentSchema,
                'release' => $current,
            ],
            'to' => [
                'app' => $target->app,
                'schema' => $target->schema,
                'release' => $target->releaseId,
            ],
            'migrations' => array_map(
                static fn (Migration $migration): array => [
                    'scope' => $migration->scope,
                    'version' => $migration->version,
                    'name' => $migration->name,
                    'reversible' => $migration->reversible,
                    'irreversible' => $migration->irreversible,
                ],
                $pending,
            ),
            'plugins' => $this->diffMap($currentVersion->plugins(), $targetVersion->plugins()),
            'themes' => $this->diffMap($currentVersion->themes(), $targetVersion->themes()),
            'backup_required' => true,
            'maintenance_required' => $schemaChange,
            'rollback_modes' => $rollbackModes,
            'point_of_no_return' => $pointOfNoReturn,
            'warnings' => $warnings,
            'created_at' => gmdate('c'),
        ];

        $this->writePlan($plan);

        return $plan;
    }

    /** @return array<string, mixed> */
    public function load(string $planId): array
    {
        if (!preg_match('/^pln_[a-z0-9_]+$/', $planId)) {
            throw new RuntimeException('Invalid plan id.');
        }

        $file = $this->planFile($planId);
        if (!is_file($file)) {
            throw new RuntimeException('Upgrade plan not found or expired.');
        }

        $plan = json_decode((string) file_get_contents($file), true);
        if (!is_array($plan) || ($plan['plan_id'] ?? null) !== $planId) {
            throw new RuntimeException('Upgrade plan is corrupt.');
        }

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    private function writePlan(array $plan): void
    {
        if (!is_dir($this->upgradeDirectory)
            && !mkdir($this->upgradeDirectory, 0775, true)
            && !is_dir($this->upgradeDirectory)
        ) {
            throw new RuntimeException('Unable to create upgrade directory.');
        }

        $file = $this->planFile((string) $plan['plan_id']);
        $temporary = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents(
            $temporary,
            json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            LOCK_EX,
        );
        chmod($temporary, 0640);
        rename($temporary, $file);
    }

    private function planFile(string $planId): string
    {
        return rtrim($this->upgradeDirectory, '/') . '/plan-' . $planId . '.json';
    }

    private function targetVersion(ReleaseManifest $manifest): Version
    {
        $file = $manifest->directory . '/version.json';
        if (!is_file($file)) {
            throw new RuntimeException('Target release is missing version.json.');
        }

        return Version::fromFile($file);
    }

    private function makePlanId(string $current, ReleaseManifest $target, string $currentApp, int $currentSchema): string
    {
        $hash = hash('sha256', implode('|', [
            $current,
            $target->releaseId,
            $currentApp,
            (string) $currentSchema,
            (string) $target->schema,
        ]));

        return 'pln_' . gmdate('Ymd_His') . '_' . substr($hash, 0, 8);
    }

    /**
     * @param array<string, string> $from
     * @param array<string, string> $to
     * @return list<array<string, string>>
     */
    private function diffMap(array $from, array $to): array
    {
        $changes = [];
        foreach ($to as $id => $version) {
            if (($from[$id] ?? null) !== $version) {
                $changes[] = ['id' => $id, 'from' => $from[$id] ?? '', 'to' => $version];
            }
        }

        return $changes;
    }
}
