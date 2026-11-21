<?php

declare(strict_types=1);

use Core\Upgrade\CommandRunner;
use Core\Upgrade\ReleaseArchive;
use Core\Upgrade\ReleaseCatalog;
use Core\Upgrade\ReleaseManifest;
use Core\Upgrade\UpdateJob;
use Core\Upgrade\UpgradeLock;
use Core\Upgrade\UpgradeStateStore;
use Core\Version\Version;

$ctx = require dirname(__DIR__) . '/bootstrap/cli.php';

$options = [];
foreach ($argv as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $option = substr($argument, 2);
    $value = '';
    if (str_contains($option, '=')) {
        [$option, $value] = explode('=', $option, 2);
    }
    $options[$option] = $value;
}
$json = isset($options['json']);

$respond = static function (array $payload, int $code) use ($json): never {
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo (string) ($payload['message'] ?? $payload['error'] ?? 'ok') . PHP_EOL;
    }
    exit($code);
};

$isRoot = static fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0;
$deployRoot = rtrim((string) (Core\Config\Env::get('DEPLOY_ROOT', $ctx['root']) ?? $ctx['root']), '/');
$storageRoot = rtrim((string) (Core\Config\Env::get('STORAGE_DIR', $ctx['storage']) ?? $ctx['storage']), '/');
$currentLink = $deployRoot . '/current';
$currentRoot = is_link($currentLink) ? (realpath($currentLink) ?: null) : null;
$jobStore = new UpdateJob($storageRoot . '/updates');

if (isset($options['health'])) {
    $respond([
        'ok' => true,
        'message' => '更新助手可用。',
        'data' => [
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'root' => $ctx['root'],
            'deploy_root' => $deployRoot,
            'current' => $currentRoot,
            'app' => $ctx['version']->app(),
        ],
    ], 0);
}

if (!$isRoot()) {
    $respond(['ok' => false, 'message' => '更新助手必须以 root 运行。'], 77);
}
if ($currentRoot === null || !is_dir($currentRoot)) {
    $respond(['ok' => false, 'message' => '当前部署没有可用的 current release，无法自动更新。'], 78);
}
if (realpath($ctx['root']) !== realpath($currentRoot)) {
    $respond(['ok' => false, 'message' => '更新助手必须从当前 release 运行。'], 78);
}

$tag = (string) ($options['tag'] ?? '');
$actor = (string) ($options['actor'] ?? 'cli');
if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) !== 1) {
    $respond(['ok' => false, 'message' => '目标发布标签格式无效。'], 2);
}
if (preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $actor) !== 1) {
    $respond(['ok' => false, 'message' => '操作者标识格式无效。'], 2);
}

$currentVersion = Version::fromFile($currentRoot . '/version.json');
$job = [
    'status' => 'running',
    'step' => 'checking',
    'message' => '正在读取发布信息。',
    'tag' => $tag,
    'actor' => $actor,
    'from' => $currentVersion->app(),
    'to' => null,
    'plan_id' => null,
    'backup_id' => null,
    'release' => null,
    'error' => null,
    'started_at' => gmdate('c'),
    'finished_at' => null,
];
$jobStore->save($job);
$step = 'checking';

$advance = static function (string $next, string $message, array $extra = []) use (&$job, &$step, $jobStore): void {
    $step = $next;
    $job['step'] = $next;
    $job['message'] = $message;
    $job['status'] = 'running';
    $job['error'] = null;
    foreach ($extra as $key => $value) {
        $job[$key] = $value;
    }
    $jobStore->save($job);
    $jobStore->appendLog($next . ' ' . $message);
};

$runner = new CommandRunner();
$runUpgrade = static function (string $command, array $arguments) use ($runner, $currentRoot): array {
    $result = $runner->run(
        array_merge([PHP_BINARY, $currentRoot . '/bin/upgrade.php', $command], $arguments, ['--json']),
        $currentRoot,
        ['PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'), 'HOME' => (string) (getenv('HOME') ?: '/root')],
    );
    $decoded = json_decode(trim($result['stdout']), true);
    if (!is_array($decoded)) {
        $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        throw new RuntimeException(sprintf('升级命令返回无法解析（exit %d）：%s', $result['code'], $detail));
    }
    if (($decoded['ok'] ?? false) !== true || $result['code'] !== 0) {
        throw new RuntimeException((string) ($decoded['message'] ?? '升级命令失败。'));
    }

    return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
};

try {
    $lock = new UpgradeLock($storageRoot . '/updates/helper.lock');
    if (!$lock->acquire()) {
        throw new RuntimeException('另一个一键更新任务正在运行。');
    }

    $upgradeState = (new UpgradeStateStore($storageRoot . '/upgrade'))->load();
    if ($upgradeState !== null && in_array((string) ($upgradeState['status'] ?? ''), ['running', 'failed'], true)) {
        throw new RuntimeException('已有升级任务未完成，请先用升级命令恢复或回退。');
    }
    if (is_file($storageRoot . '/maintenance.flag')) {
        throw new RuntimeException('站点处于维护模式，请先处理当前升级。');
    }
    $free = disk_free_space($deployRoot . '/releases');
    if ($free !== false && $free < 1073741824) {
        throw new RuntimeException('服务器剩余空间不足 1GB。');
    }

    $catalog = new ReleaseCatalog();
    $latest = $catalog->latest();
    if ($latest === null || $latest['tag'] !== $tag) {
        throw new RuntimeException('目标版本已不是 GitHub 上的最新正式发布，请刷新页面重试。');
    }
    $release = $catalog->release($tag);
    if (version_compare($release['app'], $currentVersion->app(), '<=') === true) {
        throw new RuntimeException('目标版本必须高于当前版本。');
    }

    $target = $deployRoot . '/releases/' . $tag;
    if (file_exists($target)) {
        throw new RuntimeException('目标版本目录已存在，不会覆盖现有 release。');
    }

    $advance('download', '正在下载 ' . $tag . ' 发布包。', ['to' => $release['app'], 'release' => $tag]);
    $staging = $storageRoot . '/updates/staging/' . $tag . '-' . bin2hex(random_bytes(4));
    if (!mkdir($staging, 0700, true) && !is_dir($staging)) {
        throw new RuntimeException('无法创建更新临时目录。');
    }

    $archive = $staging . '/release.zip';
    $checksum = $staging . '/release.sha256';
    $catalog->download($release['zip']['url'], $archive);
    $catalog->download($release['sha256']['url'], $checksum);
    $size = filesize($archive);
    if ($size === false || $size !== (int) $release['zip']['size']) {
        throw new RuntimeException('下载的发布包大小与 GitHub 记录不一致。');
    }

    $advance('verify', '正在校验发布包与文件清单。');
    $line = trim((string) file_get_contents($checksum));
    if (preg_match('/^([a-fA-F0-9]{64})(?:\s+([^\r\n]+))?/', $line, $matches) !== 1) {
        throw new RuntimeException('发布校验文件格式无效。');
    }
    $expected = strtolower($matches[1]);
    $filename = isset($matches[2]) ? trim($matches[2]) : '';
    if ($filename !== '' && basename($filename) !== $release['zip']['name']) {
        throw new RuntimeException('发布校验文件对应了错误的压缩包。');
    }
    $actual = hash_file('sha256', $archive);
    if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
        throw new RuntimeException('发布包 SHA256 校验失败。');
    }

    $inspection = (new ReleaseArchive($archive))->inspect();
    $jobStore->appendLog(sprintf('archive files=%d bytes=%d', $inspection['files'], $inspection['bytes']));
    $extract = $staging . '/release';
    (new ReleaseArchive($archive))->extractTo($extract);

    $manifest = ReleaseManifest::fromDirectory($extract);
    $manifest->verifyFiles();
    if ($manifest->releaseId !== $tag
        || $manifest->app !== $release['app']
        || $manifest->channel !== 'stable'
    ) {
        throw new RuntimeException('发布包清单与目标版本不一致。');
    }
    $packaged = Version::fromFile($extract . '/version.json');
    if ($packaged->app() !== $release['app'] || $packaged->schema() !== $manifest->schema) {
        throw new RuntimeException('发布包版本文件与清单不一致。');
    }
    foreach (['bin/upgrade.php', 'bin/update-helper.php', 'public/index.php'] as $required) {
        if (!is_file($extract . '/' . $required)) {
            throw new RuntimeException('发布包缺少必要文件：' . $required);
        }
    }

    $advance('install', '正在安装 ' . $tag . ' 到发布目录。');
    if (!rename($extract, $target)) {
        throw new RuntimeException('无法把发布包移动到 releases 目录。');
    }
    chmod($target, 0755);
    @unlink($archive);
    @unlink($checksum);
    @rmdir($staging);
    $jobStore->appendLog('installed ' . $target);

    $advance('plan', '正在生成升级计划。');
    $plan = $runUpgrade('plan', ['--to=' . $tag]);
    $planId = (string) ($plan['plan_id'] ?? '');
    if (preg_match('/^pln_[a-z0-9_]+$/', $planId) !== 1) {
        throw new RuntimeException('升级计划没有返回有效编号。');
    }
    $advance('plan', '升级计划已生成。', ['plan_id' => $planId]);

    $advance('apply', '正在备份、迁移并切换版本。', ['plan_id' => $planId]);
    $state = $runUpgrade('apply', ['--plan=' . $planId, '--confirm']);
    $job['plan_id'] = (string) ($state['plan_id'] ?? $planId);
    $job['backup_id'] = isset($state['backup_id']) ? (string) $state['backup_id'] : null;
    $job['release'] = (string) ($state['release'] ?? $tag);
    $job['step'] = 'done';
    $job['status'] = 'done';
    $job['message'] = '已更新到 ' . $tag . '。';
    $job['finished_at'] = gmdate('c');
    $jobStore->save($job);
    $jobStore->appendLog('done ' . $tag);
    $lock->release();

    $respond(['ok' => true, 'message' => $job['message'], 'data' => $job], 0);
} catch (Throwable $exception) {
    $job['status'] = 'failed';
    $job['step'] = $step;
    $job['error'] = $exception->getMessage();
    $job['message'] = '更新失败：' . $exception->getMessage();
    $job['finished_at'] = gmdate('c');
    try {
        $jobStore->save($job);
        $jobStore->appendLog('failed ' . $exception->getMessage());
    } catch (Throwable) {
        // 状态写入失败不能覆盖原始错误。
    }
    $respond(['ok' => false, 'message' => $exception->getMessage(), 'data' => $job], 1);
}
