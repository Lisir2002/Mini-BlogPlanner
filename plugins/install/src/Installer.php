<?php

declare(strict_types=1);

namespace Install;

use Core\Upgrade\UpgradeLock;
use Core\Config\Env;
use Core\Version\Version;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class Installer
{
    private const STEPS = ['environment', 'database', 'admin', 'site', 'run', 'finish'];

    private UpgradeLock $lock;
    private View $view;
    private EnvironmentChecker $checker;
    private DatabaseProbe $probe;
    private RateLimiter $rateLimiter;

    public function __construct(
        private readonly string $root,
        private readonly Version $version,
    ) {
        $this->lock = new UpgradeLock($root . '/storage/install.lock');
        $this->view = new View($root . '/plugins/install/views');
        $this->checker = new EnvironmentChecker();
        $this->probe = new DatabaseProbe();
        $this->rateLimiter = new RateLimiter($root . '/storage/install/rate.json');
    }

    public function run(): void
    {
        set_time_limit(300);

        if ($this->isHttps() === false && $this->isLocalRequest() === false) {
            $this->renderError(403, '必须使用 HTTPS 安装', '非本机请求必须通过 HTTPS 访问安装程序。');
        }

        if (!$this->lock->acquire()) {
            $this->renderError(409, '安装正在进行中', '另一个安装进程持有安装锁，请稍后再试。');
        }

        $step = (string) ($_GET['step'] ?? 'environment');
        if (!in_array($step, self::STEPS, true) || $step === 'finish') {
            $this->renderError(404, '安装步骤不存在', '只允许六个固定安装步骤。');
        }

        $step = $this->guardStep($step);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->rateLimiter->allow((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                $this->renderError(429, '请求过于频繁', '每分钟最多提交 10 次，请稍后再试。');
            }
            if (!$this->assertCsrf((string) ($_POST['_csrf'] ?? ''))) {
                $this->renderError(419, '页面已过期', 'CSRF 校验失败，请刷新页面后重试。');
            }

            $step = $this->handlePost($step);
        }

        $this->renderStep($step);
    }

    private function guardStep(string $step): string
    {
        $completed = $_SESSION['install_completed'] ?? [];
        $completed = is_array($completed) ? $completed : [];

        $required = [
            'database' => ['environment'],
            'admin' => ['environment', 'database'],
            'site' => ['environment', 'database', 'admin'],
            'run' => ['environment', 'database', 'admin', 'site'],
        ];

        foreach ($required[$step] ?? [] as $previous) {
            if (($completed[$previous] ?? false) !== true) {
                $this->redirect('environment');
            }
        }

        return $step;
    }

    private function handlePost(string $step): string
    {
        return match ($step) {
            'environment' => $this->postEnvironment(),
            'database' => $this->postDatabase(),
            'admin' => $this->postAdmin(),
            'site' => $this->postSite(),
            'run' => $this->postRun(),
            default => $step,
        };
    }

    private function postEnvironment(): string
    {
        $storageRoot = rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
        $result = $this->checker->check($this->root, $storageRoot);
        $_SESSION['install_environment'] = $result;

        if ($result['errors'] !== []) {
            return 'environment';
        }

        $this->complete('environment');
        $this->log('environment.ok');
        $this->redirect('database');

    }

    private function postDatabase(): string
    {
        $config = [
            'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => trim((string) ($_POST['db_name'] ?? 'cream_journal')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'password' => (string) ($_POST['db_password'] ?? ''),
            'create' => isset($_POST['db_create']),
        ];

        $result = $this->probe->probe($config);
        $stored = $config;
        unset($stored['password']);
        $_SESSION['install_database'] = $stored;
        $_SESSION['install_database_result'] = [
            'ok' => $result['ok'],
            'message' => $result['message'],
        ];

        if (!$result['ok']) {
            $this->log('database.fail', ['message' => $result['message']]);

            return 'database';
        }

        $warnings = [];
        if ($result['ngram'] !== '2') {
            $warnings[] = 'ngram_token_size 不是 2，中文搜索效果可能下降。';
        }
        if ($result['charset'] !== 'utf8mb4') {
            $warnings[] = '数据库字符集不是 utf8mb4。';
        }
        $_SESSION['install_database_warnings'] = $warnings;

        $this->complete('database');
        $this->log('database.ok', ['version' => $result['version'], 'charset' => $result['charset']]);
        $this->redirect('admin');

    }

    private function postAdmin(): string
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $errors = [];

        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username)) {
            $errors[] = '用户名需 3–32 位，只允许字母、数字、下划线、短横线。';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = '邮箱格式不正确。';
        }
        if (mb_strlen($password) < 12) {
            $errors[] = '密码至少 12 位。';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = '密码需同时包含字母和数字。';
        }
        if ($password !== $confirm) {
            $errors[] = '两次输入的密码不一致。';
        }

        $_SESSION['install_admin'] = [
            'username' => $username,
            'email' => $email,
            'errors' => $errors,
        ];

        if ($errors !== []) {
            return 'admin';
        }

        $_SESSION['install_admin_hash'] = password_hash($password, PASSWORD_ARGON2ID);
        $this->complete('admin');
        $this->log('admin.ok', ['username' => $username]);
        $this->redirect('site');

    }

    private function postSite(): string
    {
        $title = trim((string) ($_POST['site_title'] ?? ''));
        $description = trim((string) ($_POST['site_description'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $timezone = (string) ($_POST['timezone'] ?? 'UTC');
        $language = (string) ($_POST['language'] ?? 'zh-CN');
        $theme = (string) ($_POST['theme'] ?? 'cream');
        $errors = [];

        if ($title === '' || mb_strlen($title) > 80) {
            $errors[] = '站点标题必须为 1–80 个字符。';
        }
        if (mb_strlen($description) > 200) {
            $errors[] = '站点描述不能超过 200 个字符。';
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            $errors[] = 'Base URL 必须是完整的 http/https 地址。';
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors[] = '时区不在合法列表内。';
        }
        if (!in_array($language, ['zh-CN', 'en-US'], true)) {
            $errors[] = '语言只支持 zh-CN / en-US。';
        }
        if (!in_array($theme, ['cream'], true)) {
            $errors[] = '默认主题只能是 cream。';
        }

        $_SESSION['install_site'] = [
            'title' => $title,
            'description' => $description,
            'base_url' => $baseUrl,
            'timezone' => $timezone,
            'language' => $language,
            'theme' => $theme,
            'errors' => $errors,
        ];

        if ($errors !== []) {
            return 'site';
        }

        $this->complete('site');
        $this->log('site.ok', ['title' => $title, 'base_url' => $baseUrl]);
        $this->redirect('run');

    }

    private function postRun(): string
    {
        $database = $_SESSION['install_database'] ?? null;
        $database = is_array($database) ? $database : [];
        $database['password'] = (string) ($_POST['db_password'] ?? '');

        $admin = $_SESSION['install_admin'] ?? null;
        $site = $_SESSION['install_site'] ?? null;
        $hash = $_SESSION['install_admin_hash'] ?? null;

        if ($database['password'] === '' || !is_array($admin) || !is_array($site) || !is_string($hash)) {
            $_SESSION['install_run_error'] = $database['password'] === ''
                ? '请再次输入数据库密码，安装器不会把密码保存在会话里。'
                : '安装会话已过期，请从第 1 步重新开始。';

            return 'run';
        }

        try {
            $result = (new InstallExecutor($this->root, $this->version))->run($database, $admin, $site, $hash, $this->isHttps() || $this->isLocalRequest());
            $_SESSION['install_result'] = $result;
            unset($_SESSION['install_completed'], $_SESSION['install_database'], $_SESSION['install_admin_hash']);
            $this->log('install.succeeded', ['app' => $this->version->app(), 'schema' => $this->version->schema()]);

            return 'finish';
        } catch (Throwable $exception) {
            $_SESSION['install_run_error'] = $exception->getMessage();
            $this->log('install.failed', ['message' => $exception->getMessage()]);

            return 'run';
        }
    }

    private function renderStep(string $step): void
    {
        $data = $this->stepData($step);
        $this->view->render($step, $data);
    }

    /** @return array<string, mixed> */
    private function stepData(string $step): array
    {
        $titles = [
            'environment' => '环境检查',
            'database' => '数据库',
            'admin' => '管理员',
            'site' => '站点信息',
            'run' => '执行安装',
            'finish' => '安装完成',
        ];
        $data = [
            'csrf' => $this->csrf(),
            'app' => $this->version->app(),
            'title' => ($titles[$step] ?? '安装') . ' · Mini-BlogPlanner',
            'step' => $step,
            'errors' => [],
            'warnings' => [],
            'values' => [],
            'result' => [],
        ];

        if ($step === 'environment') {
            $data['environment'] = $_SESSION['install_environment'] ?? $this->checker->check(
            $this->root,
            rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/'),
        );
        }
        if ($step === 'database') {
            $data['values'] = $_SESSION['install_database'] ?? [
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'cream_journal',
                'user' => '',
                'password' => '',
                'create' => false,
            ];
            $data['warnings'] = $_SESSION['install_database_warnings'] ?? [];
            $result = $_SESSION['install_database_result'] ?? null;
            if (is_array($result) && ($result['ok'] ?? false) !== true) {
                $data['errors'][] = (string) ($result['message'] ?? '数据库连接失败。');
            }
        }
        if ($step === 'admin') {
            $data['values'] = $_SESSION['install_admin'] ?? [];
            $data['errors'] = $_SESSION['install_admin']['errors'] ?? [];
        }
        if ($step === 'site') {
            $data['values'] = $_SESSION['install_site'] ?? [
                'title' => '日记Planner',
                'description' => '',
                'base_url' => $this->guessBaseUrl(),
                'timezone' => 'Asia/Shanghai',
                'language' => 'zh-CN',
                'theme' => 'cream',
            ];
            $data['errors'] = $_SESSION['install_site']['errors'] ?? [];
        }
        if ($step === 'run') {
            $data['database'] = $_SESSION['install_database'] ?? [];
            $data['admin'] = $_SESSION['install_admin'] ?? [];
            $data['site'] = $_SESSION['install_site'] ?? [];
            $data['error'] = $_SESSION['install_run_error'] ?? null;
            unset($_SESSION['install_run_error']);
        }
        if ($step === 'finish') {
            $data['result'] = $_SESSION['install_result'] ?? [];
        }

        return $data;
    }

    private function renderError(int $status, string $title, string $message): never
    {
        http_response_code($status);
        $this->view->render('error', [
            'csrf' => $this->csrf(),
            'title' => $title,
            'message' => $message,
        ]);
        exit;
    }

    private function redirect(string $step): never
    {
        header('Location: /install.php?step=' . rawurlencode($step), true, 303);
        exit;
    }

    private function complete(string $step): void
    {
        $completed = $_SESSION['install_completed'] ?? [];
        if (!is_array($completed)) {
            $completed = [];
        }
        $completed[$step] = true;
        $_SESSION['install_completed'] = $completed;
    }

    private function csrf(): string
    {
        if (!isset($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
            $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['install_csrf'];
    }

    private function assertCsrf(string $token): bool
    {
        $expected = $_SESSION['install_csrf'] ?? null;

        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    private function isLocalRequest(): bool
    {
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    }

    private function guessBaseUrl(): string
    {
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host;
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, array $context = []): void
    {
        $directory = $this->root . '/storage/logs';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $line = json_encode([
            'event' => $event,
            'at' => gmdate('c'),
            'ip' => substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 12),
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($directory . '/install.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
