<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Audit\AuditRepository;
use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Auth\LoginThrottle;
use Core\Config\Env;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\GraphicCaptcha;
use Core\Theme\Theme;
use Throwable;

final class LoginController
{
    public function __construct(
        private readonly string $root,
        private readonly Theme $theme,
    ) {}

    public function show(Request $request): Response
    {
        return $this->render(null, 200);
    }

    public function login(Request $request): Response
    {
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->render('页面已过期，请刷新后重试。', 419);
        }

        $captcha = new GraphicCaptcha();
        if (!$captcha->verify(isset($_POST['captcha']) ? (string) $_POST['captcha'] : null)) {
            return $this->render('图形验证码不正确，请重新输入。', 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $storage = rtrim(Env::get('STORAGE_DIR', $this->root . '/storage') ?? $this->root . '/storage', '/');
        $throttle = new LoginThrottle($storage . '/auth/login.json');
        if (!$throttle->allow($ip)) {
            return $this->render('尝试次数过多，请 15 分钟后再试。', 429);
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            return $this->render('请输入用户名和密码。', 422);
        }

        try {
            $db = Connection::fromEnv();
            $auth = new Auth(new AdminRepository($db));
            $ok = $auth->login($username, $password);
        } catch (Throwable) {
            return $this->render('后台暂时无法连接数据库。', 503);
        }

        try {
            (new AuditRepository(Connection::fromEnv()))->append(
                $ok ? 'admin.login.succeeded' : 'admin.login.failed',
                $username,
                ['ip' => $ip],
            );
        } catch (Throwable) {
            // 审计失败不能阻断登录结果。
        }

        if (!$ok) {
            $throttle->hit($ip);

            return $this->render('用户名或密码不正确。', 401);
        }

        $throttle->clear($ip);
        Csrf::rotate();

        return Response::redirect('/admin/posts.html', 303);
    }

    public function captcha(Request $request): Response
    {
        $captcha = new GraphicCaptcha();

        return Response::binary($captcha->image($captcha->issue()), 'image/png')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function logout(Request $request): Response
    {
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->render('页面已过期，请刷新后重试。', 419);
        }

        try {
            $db = Connection::fromEnv();
            $auth = new Auth(new AdminRepository($db));
            $admin = $auth->user();
            $auth->logout();
            if ($admin !== null) {
                (new AuditRepository($db))->append('admin.logout', $admin->username, ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')]);
            }
        } catch (Throwable) {
            // 会话仍要失效，即使数据库暂时不可达。
        }
        Csrf::rotate();

        return Response::redirect('/admin/login.html', 303);
    }

    private function render(?string $error, int $status): Response
    {
        $html = $this->theme->render('admin/login', [
            'site' => $this->site(),
            'title' => '后台登录',
            'current' => 'login',
            'csrf' => Csrf::token(),
            'error' => $error,
        ], 'admin/layout');

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @return array{title:string,description:string,language:string} */
    private function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
    }
}
