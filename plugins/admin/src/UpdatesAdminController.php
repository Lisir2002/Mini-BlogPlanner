<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Admin\AdminNavigation;
use Core\Audit\AuditRepository;
use Core\Auth\Admin;
use Core\Config\Env;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Core\Upgrade\ReleaseCatalog;
use Core\Upgrade\ReleaseRepository;
use Core\Upgrade\UpdateJob;
use Core\Upgrade\UpgradeStateStore;
use Core\Version\Version;
use Throwable;

/**
 * 后台版本页只做读取和派发，实际写入部署目录的工作交给 root 更新助手。
 */
final class UpdatesAdminController
{
    use AdminErrors;

    private readonly AdminGuard $guard;
    private readonly string $deployRoot;
    private readonly string $storageRoot;
    private readonly UpdateJob $jobs;
    private readonly string $helper;

    public function __construct(
        private readonly Theme $theme,
        private readonly Version $version,
        string $root,
    ) {
        $this->guard = new AdminGuard($this->theme);
        $this->deployRoot = rtrim((string) (Env::get('DEPLOY_ROOT', $root) ?? $root), '/');
        $this->storageRoot = rtrim((string) (Env::get('STORAGE_DIR', $root . '/storage') ?? $root . '/storage'), '/');
        $this->jobs = new UpdateJob($this->storageRoot . '/updates');
        $this->helper = (string) (Env::get('UPDATE_HELPER', '/usr/local/bin/cream-update') ?? '/usr/local/bin/cream-update');
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $current = $this->currentVersion();
        $latest = null;
        $latestError = null;
        try {
            $latest = (new ReleaseCatalog())->latest();
        } catch (Throwable $exception) {
            $latestError = $this->safeMessage($exception->getMessage());
        }

        $job = $this->jobs->load();
        $maintenance = is_file($this->storageRoot . '/maintenance.flag');
        if ($job !== null) {
            $job = $this->jobs->reconcile($job, $current->app(), $maintenance);
            $job = $this->jobs->normalize($job);
        }
        $job = $this->sanitizeJob($job);

        $currentRelease = null;
        try {
            $currentRelease = $this->releaseRepository()->current();
        } catch (Throwable) {
            // 源码目录或权限不足时仍显示应用版本。
        }

        $updateAvailable = $latest !== null && version_compare($latest['app'], $current->app(), '>');
        $ahead = $latest !== null && version_compare($latest['app'], $current->app(), '<');
        $upgrading = $this->jobs->active($job) || $maintenance;
        $helperAvailable = is_executable($this->helper);
        $canUpdate = $updateAvailable && $helperAvailable && !$upgrading;

        $html = $this->theme->render('admin/updates', [
            'site' => $this->site(),
            'title' => AdminNavigation::label('updates'),
            'current' => 'updates',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'currentVersion' => $current,
            'latest' => $latest,
            'latestError' => $latestError,
            'updateAvailable' => $updateAvailable,
            'ahead' => $ahead,
            'canUpdate' => $canUpdate,
            'helperAvailable' => $helperAvailable,
            'upgrading' => $upgrading,
            'job' => $job,
            'jobActive' => $this->jobs->active($job),
            'currentRelease' => $currentRelease,
            'queued' => isset($request->query()['queued']),
            'phpVersion' => PHP_VERSION,
            'page_styles' => ['admin-updates.css'],
            'page_scripts' => ['admin-updates.js'],
        ], 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }

    public function apply(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $tag = trim((string) ($_POST['tag'] ?? ''));
        if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) !== 1) {
            return $this->adminError($request, 422, '目标版本参数无效。');
        }

        $job = $this->jobs->load();
        if ($this->jobs->active($job)) {
            return $this->adminError($request, 409, '已有更新任务正在运行，请等待完成。');
        }
        if (is_file($this->storageRoot . '/maintenance.flag')) {
            return $this->adminError($request, 409, '站点处于维护模式，请先处理当前升级。');
        }

        try {
            $state = (new UpgradeStateStore($this->storageRoot . '/upgrade'))->load();
        } catch (Throwable) {
            return $this->adminError($request, 503, '无法读取升级状态。');
        }
        if ($state !== null && in_array((string) ($state['status'] ?? ''), ['running', 'failed'], true)) {
            return $this->adminError($request, 409, '已有升级任务未完成，请先使用升级命令恢复或回退。');
        }

        try {
            $latest = (new ReleaseCatalog())->latest();
        } catch (Throwable $exception) {
            return $this->adminError($request, 503, '读取 GitHub 发布失败：' . $this->safeMessage($exception->getMessage()));
        }
        if ($latest === null || $latest['tag'] !== $tag) {
            return $this->adminError($request, 409, '目标版本已变化，请刷新页面后重试。');
        }
        if (version_compare($latest['app'], $this->currentVersion()->app(), '>') !== true) {
            return $this->adminError($request, 409, '当前已经是最新版本。');
        }
        if (!is_executable($this->helper)) {
            return $this->adminError($request, 503, '更新助手尚未安装，暂时不能一键更新。');
        }

        $health = ['code' => 1, 'output' => ''];
        exec(
            'sudo -n ' . escapeshellarg($this->helper) . ' --health --json 2>&1',
            $healthOutput,
            $health['code'],
        );
        $health['output'] = implode("\n", $healthOutput);
        if ($health['code'] !== 0) {
            return $this->adminError($request, 503, '更新助手无法通过 sudo 启动，请检查服务器配置。');
        }

        $job = [
            'status' => 'queued',
            'step' => 'queued',
            'message' => '更新任务已排入队列。',
            'tag' => $tag,
            'actor' => $admin->username,
            'from' => $this->currentVersion()->app(),
            'to' => $latest['app'],
            'plan_id' => null,
            'backup_id' => null,
            'release' => null,
            'error' => null,
            'started_at' => gmdate('c'),
            'finished_at' => null,
        ];
        try {
            $this->jobs->save($job);
        } catch (Throwable) {
            return $this->adminError($request, 503, '更新状态目录不可写，请检查存储权限。');
        }

        $command = 'sudo -n ' . escapeshellarg($this->helper)
            . ' --tag=' . escapeshellarg($tag)
            . ' --actor=' . escapeshellarg($admin->username)
            . ' --json 2>&1';
        $output = [];
        exec($command, $output, $code);
        if ($code !== 0) {
            $job['status'] = 'failed';
            $job['error'] = '无法启动更新助手，请检查独立任务配置。';
            $this->jobs->save($job);

            return $this->adminError($request, 503, '无法启动更新助手，请检查独立任务配置。');
        }

        $this->audit($admin, 'admin.update.started', ['tag' => $tag, 'to' => $latest['app']]);

        return Response::redirect('/admin/updates.html?queued=1', 303);
    }

    public function status(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return Response::json(['ok' => false, 'message' => '请先登录后台。'], 401);
        }

        $current = $this->currentVersion();
        $maintenance = is_file($this->storageRoot . '/maintenance.flag');
        $job = $this->jobs->load();
        if ($job !== null) {
            $job = $this->jobs->reconcile($job, $current->app(), $maintenance);
            $job = $this->sanitizeJob($this->jobs->normalize($job));
        }

        return Response::json([
            'ok' => true,
            'job' => $job,
            'current' => $current->app(),
            'maintenance' => $maintenance,
            'helper' => is_executable($this->helper),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function currentVersion(): Version
    {
        $file = $this->deployRoot . '/current/version.json';
        if (is_file($file)) {
            try {
                return Version::fromFile($file);
            } catch (Throwable) {
                // 回退到运行时版本。
            }
        }

        return $this->version;
    }

    private function releaseRepository(): ReleaseRepository
    {
        return new ReleaseRepository(
            $this->deployRoot . '/releases',
            $this->deployRoot . '/current',
            $this->deployRoot . '/previous',
        );
    }

    /** @param array<string, mixed>|null $job
     *  @return array<string, mixed>|null
     */
    private function sanitizeJob(?array $job): ?array
    {
        if ($job === null) {
            return null;
        }
        foreach (['message', 'error'] as $key) {
            if (isset($job[$key]) && is_string($job[$key])) {
                $job[$key] = $this->safeMessage($job[$key]);
            }
        }

        return $job;
    }

    private function safeMessage(string $message): string
    {
        return str_replace([$this->deployRoot, $this->storageRoot], ['[部署目录]', '[存储目录]'], $message);
    }

    /** @param array<string, mixed> $payload */
    private function audit(Admin $admin, string $event, array $payload): void
    {
        try {
            (new AuditRepository(Connection::fromEnv()))->append($event, $admin->username, $payload);
        } catch (Throwable) {
            // 审计失败不能阻断已经排队的更新。
        }
    }

    /** @return array{title:string,description:string,language:string} */
    private function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', ''),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
    }
}
