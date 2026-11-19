<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Auth\Admin;
use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Throwable;

final class AdminGuard
{
    public function __construct(private readonly Theme $theme) {}

    /** @return Admin|Response */
    public function authenticate(Request $request): Admin|Response
    {
        try {
            $auth = new Auth(new AdminRepository(Connection::fromEnv()));
            $admin = $auth->user();
        } catch (Throwable) {
            return ErrorPage::admin($this->theme, $request, 503, '后台暂时无法连接数据库。');
        }

        if ($admin === null) {
            return Response::redirect('/admin/login.html', 303);
        }

        return $admin;
    }
}
