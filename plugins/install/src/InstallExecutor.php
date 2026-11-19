<?php

declare(strict_types=1);

namespace Install;

use Core\Config\Env;
use Core\Database\Connection;
use Core\Database\MigrationLoader;
use Core\Database\Migrator;
use Core\Version\Version;
use RuntimeException;

final class InstallExecutor
{
    public function __construct(
        private readonly string $root,
        private readonly Version $version,
    ) {}

    /** @param array<string, mixed> $database
     *  @param array<string, mixed> $admin
     *  @param array<string, mixed> $site
     *  @return array<string, mixed>
     */
    public function run(array $database, array $admin, array $site, string $hash, bool $secure): array
    {
        $config = [
            'host' => (string) $database['host'],
            'port' => (int) $database['port'],
            'name' => (string) $database['name'],
            'user' => (string) $database['user'],
            'password' => (string) $database['password'],
            'create' => (bool) ($database['create'] ?? false),
        ];
        $probe = (new DatabaseProbe())->probe($config);
        if (!$probe['ok'] || $probe['pdo'] === null) {
            throw new RuntimeException($probe['message']);
        }

        $db = new Connection($probe['pdo']);
        $migrator = new Migrator($db, $this->version->app());
        $loader = new MigrationLoader();
        $migrations = $loader->load($this->root . '/database/migrations', 'core');
        $migrator->apply($migrations);

        $this->seedSettings($db, $site);
        $this->createAdmin($db, $admin, $hash);
        $this->writeEnv($database, $site, $secure);
        $this->writeLock();

        $disabled = $this->disableEntry();

        return [
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'base_url' => (string) $site['base_url'],
            'admin' => (string) $admin['username'],
            'disabled' => $disabled,
            'health_url' => (string) $site['base_url'] . '/healthz',
        ];
    }

    /** @param array<string, mixed> $site */
    private function seedSettings(Connection $db, array $site): void
    {
        $values = [
            'site.title' => $site['title'],
            'site.description' => $site['description'],
            'site.base_url' => $site['base_url'],
            'site.timezone' => $site['timezone'],
            'site.language' => $site['language'],
            'site.theme' => $site['theme'],
        ];

        foreach ($values as $key => $value) {
            $db->execute(
                <<<'SQL'
INSERT INTO site_settings (setting_key, setting_value)
VALUES (?, ?) AS new
ON DUPLICATE KEY UPDATE setting_value = new.setting_value
SQL,
                [$key, json_encode($value, JSON_THROW_ON_ERROR)],
            );
        }
    }

    /** @param array<string, mixed> $admin */
    private function createAdmin(Connection $db, array $admin, string $hash): void
    {
        $db->execute(
            'INSERT INTO admins (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)',
            [$admin['username'], $admin['email'], $hash, 'admin', 'active'],
        );
    }

    /** @param array<string, mixed> $database
     *  @param array<string, mixed> $site
     */
    private function writeEnv(array $database, array $site, bool $secure): void
    {
        $path = $this->root . '/.env';
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        $lines = [
            'APP_NAME=' . $this->quote((string) $site['title']),
            'APP_DESCRIPTION=' . $this->quote((string) $site['description']),
            'APP_LOCALE=' . $this->quote((string) $site['language']),
            'APP_THEME=' . $this->quote((string) $site['theme']),
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . $this->quote((string) $site['base_url']),
            'APP_KEY=base64:' . base64_encode(random_bytes(32)),
            'APP_TIMEZONE=' . $this->quote((string) $site['timezone']),
            'SESSION_SECURE=' . ($secure ? 'true' : 'false'),
            'DB_HOST=' . $this->quote((string) $database['host']),
            'DB_PORT=' . (int) $database['port'],
            'DB_NAME=' . $this->quote((string) $database['name']),
            'DB_USER=' . $this->quote((string) $database['user']),
            'DB_PASSWORD=' . $this->quote((string) $database['password']),
        ];
        $storage = Env::get('STORAGE_DIR');
        if ($storage !== null && $storage !== '') {
            $lines[] = 'STORAGE_DIR=' . $this->quote($storage);
        }
        $deploy = Env::get('DEPLOY_ROOT');
        if ($deploy !== null && $deploy !== '') {
            $lines[] = 'DEPLOY_ROOT=' . $this->quote($deploy);
        }

        if (file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 .env 临时文件。');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('无法替换 .env。');
        }
        chmod($path, 0600);

        $deploy = Env::get('DEPLOY_ROOT');
        if ($deploy !== null && $deploy !== '' && realpath($deploy) !== realpath($this->root)) {
            if (is_dir($deploy)) {
                $shared = rtrim($deploy, '/') . '/.env';
                if (!copy($path, $shared)) {
                    throw new RuntimeException('无法写入部署根 .env。');
                }
                chmod($shared, 0600);
            }
        }
    }

    private function writeLock(): void
    {
        $storage = rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
        if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
            throw new RuntimeException('无法创建存储目录。');
        }
        $file = $storage . '/installed.lock';
        $data = [
            'installed_at' => gmdate('c'),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
        ];
        if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 installed.lock。');
        }
        chmod($file, 0640);
    }

    private function disableEntry(): bool
    {
        // 入口文件保留在发布目录里以维持 manifest 稳定性；installed.lock 存在时
        // public/install.php 自身返回 404，逻辑上已经关闭。
        return is_file($this->root . '/public/install.php');
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new RuntimeException('.env 写入值不能包含换行。');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
