<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Admin\AdminNavigation;
use Core\Api\ApiScope;
use Core\Api\ApiToken;
use Core\Api\ApiTokenRepository;
use Core\Audit\AuditRepository;
use Core\Config\Env;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Page\Pagination;
use Core\Theme\Theme;
use Throwable;

/**
 * 后台 API 令牌页。
 *
 * 令牌明文只在创建成功后展示一次，数据库与审计只保留前缀和哈希。
 */
final class ApiTokenAdminController
{
    use AdminErrors;

    private const EXPIRY_OPTIONS = [
        '7' => '7 天',
        '30' => '30 天',
        '90' => '90 天',
        '365' => '365 天',
        'never' => '长期有效',
    ];

    private readonly AdminGuard $guard;

    public function __construct(private readonly Theme $theme)
    {
        $this->guard = new AdminGuard($this->theme);
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $page = max(1, (int) ($request->query()['page'] ?? 1));
        $created = null;
        $flash = $_SESSION['api_token_flash'] ?? null;
        unset($_SESSION['api_token_flash']);
        if (is_array($flash) && isset($flash['token'], $flash['name'], $flash['prefix'])) {
            $created = [
                'token' => (string) $flash['token'],
                'name' => (string) $flash['name'],
                'prefix' => (string) $flash['prefix'],
            ];
        }

        try {
            $db = Connection::fromEnv();
            $repository = new ApiTokenRepository($db);
            $total = $repository->count();
            $state = Pagination::resolve($page, $total);
            $tokens = $repository->page($state['per_page'], $state['offset']);
            $activeCount = $repository->activeCount();
        } catch (Throwable) {
            return $this->adminError($request, 503, 'API 令牌暂时不可用，请稍后重试。');
        }

        $rows = array_map(static fn (ApiToken $token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'prefix' => $token->prefix,
            'scopes' => $token->scopes,
            'scope_labels' => array_map(static fn (string $scope): string => ApiScope::label($scope), $token->scopes),
            'created_at' => $token->createdAt,
            'last_used_at' => $token->lastUsedAt,
            'expires_at' => $token->expiresAt,
            'revoked_at' => $token->revokedAt,
            'status' => $token->isRevoked() ? 'revoked' : ($token->isExpired() ? 'expired' : 'active'),
        ], $tokens);

        $pageUrl = static fn (int $number): string => $number <= 1 ? '/admin/tokens.html' : '/admin/tokens/page/' . $number . '.html';
        $links = [];
        foreach (Pagination::window($state['page'], $state['pages']) as $number) {
            $links[] = $number === null ? ['number' => null, 'url' => ''] : ['number' => $number, 'url' => $pageUrl($number)];
        }

        try {
            $auditTotal = (new AuditRepository(Connection::fromEnv()))->count(['event' => 'api.token.created']);
        } catch (Throwable) {
            $auditTotal = 0;
        }

        $html = $this->theme->render('admin/tokens', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => AdminNavigation::label('tokens'),
            'current' => 'tokens',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'tokens' => $rows,
            'total' => $total,
            'activeCount' => $activeCount,
            'createdCount' => $auditTotal,
            'created' => $created,
            'scopes' => ApiScope::LABELS,
            'expiryOptions' => self::EXPIRY_OPTIONS,
            'page' => $state['page'],
            'pages' => $state['pages'],
            'pageLinks' => $links,
            'prevUrl' => $state['page'] > 1 ? $pageUrl($state['page'] - 1) : null,
            'nextUrl' => $state['page'] < $state['pages'] ? $pageUrl($state['page'] + 1) : null,
            'page_styles' => ['admin-tokens.css'],
            'page_scripts' => ['admin-tokens.js'],
        ], 'admin/layout');

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }

    public function create(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            return $this->adminError($request, 422, '令牌名称需要 2 到 60 个字。');
        }

        $scopes = $_POST['scopes'] ?? [];
        $scopes = is_array($scopes) ? array_values(array_unique(array_filter($scopes, 'is_string'))) : [];
        $scopes = array_values(array_intersect($scopes, ApiScope::all()));
        if ($scopes === []) {
            return $this->adminError($request, 422, '至少选择一个令牌作用域。');
        }

        $expiry = (string) ($_POST['expires'] ?? '90');
        if (!array_key_exists($expiry, self::EXPIRY_OPTIONS)) {
            return $this->adminError($request, 422, '有效期选项无效。');
        }
        $expiresAt = $expiry === 'never' ? null : date('Y-m-d H:i:s', time() + ((int) $expiry * 86400));

        try {
            $db = Connection::fromEnv();
            /** @var array{id:int,prefix:string,token:string} $issued */
            $issued = $db->transaction(static function (Connection $connection) use ($name, $scopes, $expiresAt, $admin): array {
                $issued = (new ApiTokenRepository($connection))->issue($name, $scopes, $expiresAt, $admin->id);
                (new AuditRepository($connection))->append('api.token.created', $admin->username, [
                    'id' => $issued['id'],
                    'prefix' => $issued['prefix'],
                    'name' => $name,
                    'scopes' => $scopes,
                    'expires_at' => $expiresAt,
                ]);

                return $issued;
            });
        } catch (Throwable) {
            return $this->adminError($request, 503, '创建令牌失败，请稍后重试。');
        }

        $_SESSION['api_token_flash'] = [
            'token' => $issued['token'],
            'name' => $name,
            'prefix' => $issued['prefix'],
        ];

        return Response::redirect('/admin/tokens.html?created=1', 303);
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->adminError($request, 419, '页面已过期，请刷新后重试。');
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            return $this->adminError($request, 422, '令牌编号无效。');
        }

        try {
            $db = Connection::fromEnv();
            $revoked = $db->transaction(static function (Connection $connection) use ($id, $admin): bool {
                if (!(new ApiTokenRepository($connection))->revoke($id)) {
                    return false;
                }
                (new AuditRepository($connection))->append('api.token.revoked', $admin->username, ['id' => $id]);

                return true;
            });
            if (!$revoked) {
                return $this->adminError($request, 404, '令牌不存在或已经撤销。');
            }
        } catch (Throwable) {
            return $this->adminError($request, 503, '撤销令牌失败，请稍后重试。');
        }

        return Response::redirect('/admin/tokens.html?revoked=1', 303);
    }
}
