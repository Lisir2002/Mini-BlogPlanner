<?php

declare(strict_types=1);

use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\TagRepository;
use Core\Database\ConnectionInterface;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Page\PageRepository;
use Core\Routing\Route;
use Core\Routing\Router;
use Core\Database\Migration;
use Core\Database\MigrationLoader;
use Core\Upgrade\ReleaseManifest;
use Core\Upgrade\ReleaseRepository;
use Core\Version\SchemaAhead;
use Core\Version\SchemaBehind;
use Core\Version\SchemaGuard;
use Core\Version\SchemaMissing;
use Core\Version\Version;
use Plugin\Admin\HtmlSanitizer;

require dirname(__DIR__) . '/bootstrap/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

final class FakeConnection implements ConnectionInterface
{
    /** @param array<string, mixed>|null $row */
    public function __construct(private ?array $row = null) {}

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}

$tests = 0;
$failures = 0;

function check(string $name, callable $test): void
{
    global $tests, $failures;
    $tests++;

    try {
        $test();
        echo '.';
    } catch (Throwable $exception) {
        $failures++;
        echo PHP_EOL . 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function truthy(bool $condition, string $message = 'Condition is false'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(string $class, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw new RuntimeException(sprintf('Expected %s, got %s', $class, $exception::class));
    }

    throw new RuntimeException(sprintf('Expected %s, nothing was thrown', $class));
}

function rrmdir(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

$validVersion = [
    'app' => '0.1.0',
    'channel' => 'stable',
    'schema' => 2026112001,
    'schema_min' => 2026100101,
    'api' => '1.0.0',
    'php' => '>=8.5.0',
    'mysql' => '>=9.7.4',
    'plugins' => [],
    'themes' => [],
];

check('Version exposes the five axes', function () use ($validVersion): void {
    $version = Version::fromArray($validVersion);
    same('0.1.0', $version->app());
    same(2026112001, $version->schema());
    same(2026100101, $version->schemaMin());
    same('1.0.0', $version->api());
});

check('Version rejects schema_min above schema', function () use ($validVersion): void {
    $bad = $validVersion;
    $bad['schema_min'] = 2026112002;
    throws(RuntimeException::class, static fn () => Version::fromArray($bad));
});

check('Version rejects non-SemVer app', function () use ($validVersion): void {
    $bad = $validVersion;
    $bad['app'] = '1.4';
    throws(RuntimeException::class, static fn () => Version::fromArray($bad));
});

check('SchemaGuard rejects missing ledger', function () use ($validVersion): void {
    $guard = new SchemaGuard(new FakeConnection(null), Version::fromArray($validVersion));
    throws(SchemaMissing::class, static fn () => $guard->assertCompatible());
});

check('SchemaGuard rejects schema ahead', function () use ($validVersion): void {
    $guard = new SchemaGuard(new FakeConnection(['schema_version' => 2026112002]), Version::fromArray($validVersion));
    throws(SchemaAhead::class, static fn () => $guard->assertCompatible());
});

check('SchemaGuard rejects schema behind', function () use ($validVersion): void {
    $guard = new SchemaGuard(new FakeConnection(['schema_version' => 2026090101]), Version::fromArray($validVersion));
    throws(SchemaBehind::class, static fn () => $guard->assertCompatible());
});

check('SchemaGuard accepts a compatible window', function () use ($validVersion): void {
    $guard = new SchemaGuard(new FakeConnection(['schema_version' => 2026100101]), Version::fromArray($validVersion));
    $guard->assertCompatible();
});

check('Migration loads with checksum and source', function (): void {
    $directory = sys_get_temp_dir() . '/cj-migration-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory . '/2026112001_create_audit_logs.php';
    file_put_contents($file, <<<'PHP'
<?php
declare(strict_types=1);
use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\TagRepository;
use Core\Database\ConnectionInterface;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Page\PageRepository;
use Core\Routing\Route;
use Core\Routing\Router;
use Core\Database\Migration;
return new Migration(
    scope: 'core',
    version: 2026112001,
    name: 'demo',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {},
    down: static function (ConnectionInterface $db): void {},
);
PHP);
    $migration = Migration::fromFile($file);
    same(2026112001, $migration->version);
    same(64, strlen($migration->checksum));
    same($file, $migration->file);
    rrmdir($directory);
});

check('Migration rejects reversible without down', function (): void {
    throws(RuntimeException::class, static fn () => new Migration(
        scope: 'core',
        version: 2026112001,
        name: 'bad',
        reversible: true,
        irreversible: false,
        up: static function (ConnectionInterface $db): void {},
        down: null,
    ));
});

check('MigrationLoader rejects duplicate scope version', function (): void {
    $directory = sys_get_temp_dir() . '/cj-loader-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $body = <<<'PHP'
<?php
declare(strict_types=1);
use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\TagRepository;
use Core\Database\ConnectionInterface;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Page\PageRepository;
use Core\Routing\Route;
use Core\Routing\Router;
use Core\Database\Migration;
return new Migration(
    scope: 'core',
    version: 2026112001,
    name: 'demo',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {},
    down: static function (ConnectionInterface $db): void {},
);
PHP;
    file_put_contents($directory . '/2026112001_a.php', $body);
    file_put_contents($directory . '/2026112001_b.php', $body);
    throws(RuntimeException::class, static fn () => (new MigrationLoader())->load($directory, 'core'));
    rrmdir($directory);
});

check('ReleaseRepository switches atomically and remembers previous', function (): void {
    $base = sys_get_temp_dir() . '/cj-release-' . bin2hex(random_bytes(4));
    $releasesDir = $base . '/releases';
    mkdir($releasesDir . '/1.0.0', 0775, true);
    mkdir($releasesDir . '/1.1.0', 0775, true);

    foreach (['1.0.0', '1.1.0'] as $release) {
        file_put_contents($releasesDir . '/' . $release . '/version.json', json_encode([
            'app' => $release,
            'channel' => 'stable',
            'schema' => 2026112001,
            'schema_min' => 2026100101,
            'api' => '1.0.0',
            'php' => '>=8.5.0',
            'mysql' => '>=9.7.4',
            'plugins' => [],
            'themes' => [],
        ]));
        file_put_contents($releasesDir . '/' . $release . '/index.php', '<?php');
        file_put_contents($releasesDir . '/' . $release . '/RELEASE_MANIFEST.json', json_encode([
            'release_id' => $release,
            'app' => $release,
            'channel' => 'stable',
            'schema' => 2026112001,
            'api' => '1.0.0',
            'commit' => 'abc1234',
            'built_at' => '2026-11-20T00:00:00Z',
            'artifact_sha256' => str_repeat('a', 64),
            'files_sha256' => [
                'version.json' => hash_file('sha256', $releasesDir . '/' . $release . '/version.json'),
                'index.php' => hash_file('sha256', $releasesDir . '/' . $release . '/index.php'),
            ],
        ]));
    }

    $repository = new ReleaseRepository($releasesDir, $base . '/current', $base . '/previous');
    $repository->switchTo('1.0.0');
    same('1.0.0', $repository->current());
    $repository->switchTo('1.1.0');
    same('1.1.0', $repository->current());
    same('1.0.0', $repository->previous());
    same(['1.1.0', '1.0.0'], $repository->list());
    $repository->manifest('1.1.0', true)->verifyFiles();
    rrmdir($base);
});

check('UpgradePlanner builds a down/restore plan', function () use ($validVersion): void {
    $base = sys_get_temp_dir() . '/cj-plan-' . bin2hex(random_bytes(4));
    $releasesDir = $base . '/releases';
    mkdir($releasesDir . '/0.1.0', 0775, true);
    mkdir($releasesDir . '/0.2.0/database/migrations', 0775, true);

    $releaseVersion = static function (string $app, int $schema, int $min): string {
        return json_encode([
            'app' => $app,
            'channel' => 'stable',
            'schema' => $schema,
            'schema_min' => $min,
            'api' => '1.0.0',
            'php' => '>=8.5.0',
            'mysql' => '>=9.7.4',
            'plugins' => [],
            'themes' => [],
        ], JSON_THROW_ON_ERROR);
    };

    foreach (['0.1.0' => [2026100101, 2026100101], '0.2.0' => [2026112001, 2026100101]] as $app => [$schema, $min]) {
        file_put_contents($releasesDir . '/' . $app . '/version.json', $releaseVersion($app, $schema, $min));
        file_put_contents($releasesDir . '/' . $app . '/index.php', '<?php');
    }
    file_put_contents($releasesDir . '/0.2.0/database/migrations/2026112001_add_demo.php', <<<'PHP'
<?php
declare(strict_types=1);
use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\TagRepository;
use Core\Database\ConnectionInterface;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Page\PageRepository;
use Core\Routing\Route;
use Core\Routing\Router;
use Core\Database\Migration;
return new Migration(
    scope: 'core',
    version: 2026112001,
    name: 'add_demo',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {},
    down: static function (ConnectionInterface $db): void {},
);
PHP);

    foreach (['0.1.0', '0.2.0'] as $app) {
        $dir = $releasesDir . '/' . $app;
        $files = ['version.json' => hash_file('sha256', $dir . '/version.json')];
        $migration = $dir . '/database/migrations/2026112001_add_demo.php';
        if (is_file($migration)) {
            $files['database/migrations/2026112001_add_demo.php'] = hash_file('sha256', $migration);
        }
        file_put_contents($dir . '/RELEASE_MANIFEST.json', json_encode([
            'release_id' => $app,
            'app' => $app,
            'channel' => 'stable',
            'schema' => $app === '0.1.0' ? 2026100101 : 2026112001,
            'api' => '1.0.0',
            'commit' => 'abc1234',
            'built_at' => '2026-11-20T00:00:00Z',
            'artifact_sha256' => str_repeat('a', 64),
            'files_sha256' => $files,
        ], JSON_THROW_ON_ERROR));
    }

    $repository = new ReleaseRepository($releasesDir, $base . '/current', $base . '/previous');
    $repository->switchTo('0.1.0');

    $db = new FakeConnection(['schema_version' => 2026100101]);
    $migrator = new Core\Database\Migrator($db, '0.1.0');
    $currentVersion = Version::fromArray($validVersion);
    $planner = new Core\Upgrade\UpgradePlanner($repository, $migrator, $base . '/upgrade');
    $plan = $planner->plan('0.2.0');

    same('down', $plan['rollback_modes'][0]);
    same(true, $plan['backup_required']);
    same(1, count($plan['migrations']));
    same(2026112001, $plan['to']['schema']);
    truthy(is_file($base . '/upgrade/plan-' . $plan['plan_id'] . '.json'), 'Plan file missing');
    rrmdir($base);
});

check('HtmlSanitizer removes executable markup and unsafe URLs', function (): void {
    $html = (new HtmlSanitizer())->clean(
        '<p onclick="x()">你好<script>alert(1)</script>' .
        '<a href="javascript:alert(1)">坏链接</a>' .
        '<a href="https://example.com" target="_blank">好链接</a>' .
        '<img src="https://example.com/a.png" onerror="x" alt="图">' .
        '<iframe src="https://evil.example"></iframe></p>'
    );
    truthy(str_contains($html, '<p>你好'), 'Safe paragraph content was lost');
    truthy(!str_contains($html, 'script'), 'Script tag survived');
    truthy(!str_contains($html, 'onclick'), 'Event handler survived');
    truthy(!str_contains($html, 'javascript:'), 'Unsafe URL survived');
    truthy(str_contains($html, 'rel="noopener noreferrer"'), 'Link hardening missing');
    truthy(!str_contains($html, 'iframe'), 'Embedded page survived');
});

check('Media URL uses stable id and disk basename', function (): void {
    $media = new Media(7, '2026/11/abcdef.png', '图.png', 'image/png', 100, 20, 10, '2026-11-20 00:00:00');
    same('/media/7/abcdef.png', $media->url());
});

check('TagRepository normalizes names and caps at eight', function (): void {
    $names = TagRepository::normalize([' 生活 ', '生活', '手账', '', 'A', 'a', '二', '三', '四', '五', '六', '七']);
    same(['生活', '手账', 'A', '二', '三', '四', '五', '六'], $names);
});

check('Auth logs in, expires idle sessions and logs out', function (): void {
    Session::start();
    $_SESSION = [];
    $hash = password_hash('correct-horse-9', PASSWORD_ARGON2ID);
    $row = ['id' => 1, 'username' => 'writer', 'email' => 'writer@example.com', 'role' => 'admin', 'password_hash' => $hash];
    $auth = new Auth(new AdminRepository(new FakeConnection($row)));
    truthy(!$auth->login('writer', 'wrong'), 'Wrong password must fail');
    truthy($auth->login('writer', 'correct-horse-9'), 'Correct password must pass');
    truthy($auth->user()?->username === 'writer', 'Session user missing');
    $_SESSION['admin_seen'] = time() - 8000;
    truthy($auth->user() === null, 'Idle session must expire');
    $auth->logout();
    truthy($auth->user() === null, 'Logout must clear user');
});

check('Csrf token validates only matching value', function (): void {
    Session::start();
    $_SESSION = [];
    $token = Csrf::token();
    truthy(Csrf::validate($token), 'Valid token rejected');
    truthy(!Csrf::validate($token . 'x'), 'Tampered token accepted');
    Csrf::rotate();
    truthy(!Csrf::validate($token), 'Rotated token accepted');
});

check('PageRepository loads a callable page with assets', function () use ($validVersion): void {
    $root = sys_get_temp_dir() . '/cj-page-' . bin2hex(random_bytes(4));
    mkdir($root . '/content/pages', 0775, true);
    file_put_contents($root . '/content/pages/hello.php', <<<'PHP'
<?php
return static fn ($context) => [
    'title' => 'Hello',
    'content' => '<p>' . htmlspecialchars($context->slug) . '</p>',
    'styles' => ['pages/hello.css'],
];
PHP);
    $page = (new PageRepository($root, Version::fromArray($validVersion)))->find('hello');
    truthy($page !== null, 'Expected page to load');
    same('Hello', $page->title());
    same('<p>hello</p>', $page->content());
    same(['pages/hello.css'], $page->styles());
    rrmdir($root);
});

check('Env unescapes quoted values', function (): void {
    $file = sys_get_temp_dir() . '/cj-env-' . bin2hex(random_bytes(4));
    file_put_contents($file, 'CJ_TEST="line\\"quote\\\\slash"' . PHP_EOL);
    Env::load($file);
    same('line"quote\\slash', Env::get('CJ_TEST'));
    unlink($file);
});

check('Router strips .html and decodes route params', function (): void {
    $router = new Router();
    $router->add(new Route(['GET'], '/posts/{slug}', static function (Request $request, array $params): Response {
        return Response::text($params['slug']);
    }));

    $request = new Request('GET', '/posts/hello%20world.html', [], []);
    $match = $router->match($request);
    truthy($match !== null, 'Expected .html route to match');
    same('hello world', $match->parameters()['slug']);
});

check('Router maps index.html to the home route', function (): void {
    $router = new Router();
    $router->add(new Route(['GET'], '/', static fn (Request $request, array $params): Response => Response::text('home')));

    same('/', $router->match(new Request('GET', '/index.html', [], []))?->route()->pattern());
    same('/', $router->normalize('/index.html'));
});

check('Router reports allowed methods for a path', function (): void {
    $router = new Router();
    $router->add(new Route(['GET'], '/about', static fn (Request $request, array $params): Response => Response::text('about')));

    same(['GET', 'HEAD'], $router->allowedMethods('/about.html'));
});

check('Error pages render a themed document for every status', function (): void {
    $theme = Theme::load(dirname(__DIR__), 'cream');
    $version = Version::fromFile(dirname(__DIR__) . '/version.json');
    $site = ['title' => '日记Planner', 'description' => 'test', 'language' => 'zh-CN'];

    foreach ([400, 401, 403, 404, 405, 419, 422, 429, 500, 502, 503, 504] as $status) {
        $response = ErrorPage::response(
            $theme,
            $version,
            new Request('GET', '/missing.html', [], []),
            $status,
            'Error',
            '',
            $site,
        );

        same($status, $response->status(), 'Unexpected status for error page');
        same('text/html; charset=utf-8', $response->headers()['Content-Type'] ?? '', 'Error page must be HTML');
        truthy(str_contains($response->body(), 'error-page--' . $status), 'Missing status-specific template marker');
        truthy(str_contains($response->body(), 'error-card'), 'Missing themed error card');
        truthy(!str_contains($response->body(), '<input'), 'Error page must not contain native inputs');
        truthy(!str_contains($response->body(), '<button'), 'Error page must not contain native buttons');
    }
});



check('Paper theme uses local fiber and layered edges', function (): void {
    $root = dirname(__DIR__);
    $cream = file_get_contents($root . '/public/assets/cream.css');
    $posts = file_get_contents($root . '/public/assets/posts.css');
    $comments = file_get_contents($root . '/public/assets/comments.css');
    truthy($cream !== false && str_contains($cream, '--paper-fiber'), 'Missing paper fiber token');
    truthy($cream !== false && str_contains($cream, '--paper-shadow'), 'Missing layered paper shadow');
    truthy($cream !== false && str_contains($cream, 'hero-tape'), 'Missing hero tape detail');
    truthy($posts !== false && str_contains($posts, '.post-page::before'), 'Article page must use the paper grain layer');
    truthy($comments !== false && str_contains($comments, 'width: min(820px'), 'Comment sheet must align with the article sheet');
});

check('Brand separates project and site names', function (): void {
    $root = dirname(__DIR__);
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $footer = file_get_contents($root . '/themes/cream/templates/partials/site-footer.php');
    $readme = file_get_contents($root . '/README.md');
    truthy($header !== false && str_contains($header, 'MINI-BLOGPLANNER'), 'Header must show the project name');
    truthy($header !== false && str_contains($header, "cream_e(\$siteTitle)"), 'Header must show the configured site name');
    truthy($footer !== false && str_contains($footer, 'Mini-BlogPlanner v'), 'Footer must credit the project');
    truthy($readme !== false && str_starts_with($readme, "# Mini-BlogPlanner"), 'README must use the project name');
});

check('Comment form rejects stale and malformed timestamps', function (): void {
    truthy(!\Plugin\Posts\CommentForm::validate('not-a-timestamp'), 'Malformed timestamp must fail');
    truthy(!\Plugin\Posts\CommentForm::validate((string) (time() - 7200)), 'Stale form must fail');
    truthy(!\Plugin\Posts\CommentForm::validate((string) (time() + 300)), 'Future timestamp must fail');
});

check('Comment templates and assets are present', function (): void {
    $root = dirname(__DIR__);
    foreach (['themes/cream/templates/partials/comments.php', 'themes/cream/templates/admin/comments.php', 'public/assets/comments.css', 'public/assets/comments.css.gz'] as $file) {
        truthy(is_file($root . '/' . $file), 'Missing comment asset: ' . $file);
    }
    $partial = file_get_contents($root . '/themes/cream/templates/partials/comments.php');
    truthy($partial !== false, 'Unable to read comment partial');
    truthy(!str_contains($partial, '<select'), 'Comment form must not use native select');
    truthy(!str_contains($partial, 'type="file"'), 'Comment form must not use native file input');
    truthy(str_contains($partial, 'comment-honeypot'), 'Comment form must render the honeypot');
});

check('Comment cards use layered paper texture', function (): void {
    $root = dirname(__DIR__);
    $css = file_get_contents($root . '/public/assets/comments.css');
    $partial = file_get_contents($root . '/themes/cream/templates/partials/comments.php');
    truthy($css !== false && str_contains($css, '--paper-fiber'), 'Comment cards must use paper fiber');
    truthy($css !== false && str_contains($css, '.comment-item::before'), 'Comment cards need a tape tab');
    truthy($css !== false && str_contains($css, '.comment-replies::before'), 'Replies need a bound-thread marker');
    truthy($css !== false && str_contains($css, 'repeating-linear-gradient(180deg'), 'Comment body must use ruled lines');
    truthy($partial !== false && str_contains($partial, 'comment-form-tape'), 'Comment form needs a tape decoration');
});

check('Comment composer starts collapsed and opens only when needed', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/comments.php');
    $css = file_get_contents($root . '/public/assets/comments.css');
    truthy($partial !== false && str_contains($partial, '<details class="comment-composer" id="comment-form"'), 'Composer must be a details sheet');
    truthy($partial !== false && str_contains($partial, '$composerOpen ?'), 'Composer must honour the open condition');
    truthy($partial !== false && str_contains($partial, 'comment-composer-toggle'), 'Composer needs a themed summary');
    truthy($partial !== false && !str_contains($partial, 'id="comment-form" method="post"'), 'Form id must move to the details sheet');
    truthy($css !== false && str_contains($css, '.comment-composer[open] .comment-form'), 'Open composer must join its form sheet');
});

check('Comment email switch gates the optional email field', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/comments.php');
    $css = file_get_contents($root . '/public/assets/comments.css');
    $controller = file_get_contents($root . '/plugins/posts/src/CommentController.php');
    truthy($partial !== false && str_contains($partial, 'class="comment-notify-input"'), 'Comment form needs a themed email switch');
    truthy($partial !== false && str_contains($partial, 'name="notify_email"'), 'Email switch needs a posted state');
    truthy($partial !== false && str_contains($partial, 'comment-email-field'), 'Email field needs a disclosure wrapper');
    truthy($partial !== false && str_contains($partial, 'class="comment-email-row"'), 'Email switch must share the email label row');
    truthy($partial !== false && str_contains($partial, 'class="comment-email-toggle"'), 'Email label row needs a themed switch control');
    truthy($partial !== false && str_contains($partial, 'comment-email-title'), 'Email label must name the switch row');
    truthy($css !== false && str_contains($css, ':has(.comment-notify-input:checked) .comment-email-field'), 'Checked switch must reveal the email field');
    truthy($css !== false && str_contains($css, '.comment-notify-thumb'), 'Email switch must draw its own thumb');
    truthy($controller !== false && str_contains($controller, "['notify_email']"), 'Controller must read the email switch');
    truthy($controller !== false && str_contains($controller, '$notifyEmail ?'), 'Controller must ignore email when the switch is off');
});

check('Comment replies open in place without a page reload', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/comments.php');
    $controller = file_get_contents($root . '/plugins/posts/src/PostController.php');
    $script = file_get_contents($root . '/public/assets/comments.js');
    truthy($partial !== false && str_contains($partial, 'data-comment-reply="'), 'Reply links need an in-page target');
    truthy($partial !== false && str_contains($partial, 'data-comment-author="'), 'Reply links need the author name');
    truthy($partial !== false && str_contains($partial, 'data-comment-parent'), 'Reply parent id needs a script hook');
    truthy($partial !== false && str_contains($partial, 'data-comment-reply-context'), 'Reply context must exist before the first click');
    truthy($partial !== false && str_contains($partial, 'data-comment-cancel'), 'Cancel reply needs an in-page hook');
    truthy($controller !== false && str_contains($controller, "'comments.js'"), 'Post pages must load the comment script');
    truthy($script !== false && str_contains($script, 'event.preventDefault()'), 'Reply click must suppress navigation');
    truthy($script !== false && str_contains($script, 'composer.open = true'), 'Reply click must open the composer');
    truthy($script !== false && str_contains($script, 'history.replaceState'), 'Reply state must stay shareable without reloading');
});

check('Post reactions store one anonymous attitude per visitor', function (): void {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112006_create_post_reactions.php');
    $repository = file_get_contents($root . '/core/Content/ReactionRepository.php');
    truthy($migration !== false && str_contains($migration, 'CREATE TABLE IF NOT EXISTS post_reactions'), 'Missing post_reactions table');
    truthy($migration !== false && str_contains($migration, 'visitor_hash CHAR(64)'), 'Reaction visitor must be hashed');
    truthy($migration !== false && str_contains($migration, 'ip_hash CHAR(64)'), 'Reaction IP must be hashed');
    truthy($migration !== false && str_contains($migration, 'uq_post_reactions_visitor'), 'Reaction visitor must be unique per post');
    truthy($migration !== false && str_contains($migration, 'idx_post_reactions_tally'), 'Reaction tally needs an index');
    truthy($repository !== false && str_contains($repository, 'ON DUPLICATE KEY UPDATE'), 'Reaction writes must be idempotent');
    truthy($repository !== false && str_contains($repository, 'CASE WHEN value = 1'), 'Repository must aggregate likes');
    truthy($repository !== false && str_contains($repository, 'CASE WHEN value = -1'), 'Repository must aggregate dislikes');
});

check('Post reactions render themed controls and stay in place', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/reactions.php');
    $post = file_get_contents($root . '/themes/cream/templates/post.php');
    $controller = file_get_contents($root . '/plugins/posts/src/ReactionController.php');
    $plugin = file_get_contents($root . '/plugins/posts/src/PostsPlugin.php');
    $view = file_get_contents($root . '/plugins/posts/src/PostController.php');
    $css = file_get_contents($root . '/public/assets/reactions.css');
    $script = file_get_contents($root . '/public/assets/reactions.js');
    $icons = file_get_contents($root . '/public/assets/icons.svg');
    truthy($partial !== false && str_contains($partial, 'data-reaction-widget'), 'Reaction section needs a script hook');
    truthy($partial !== false && str_contains($partial, 'aria-pressed='), 'Reaction buttons need pressed state');
    truthy($partial !== false && str_contains($partial, 'data-reaction-count="likes"'), 'Reaction likes need a live count');
    truthy($partial !== false && str_contains($partial, 'data-reaction-count="dislikes"'), 'Reaction dislikes need a live count');
    truthy($partial !== false && str_contains($partial, 'action="/posts/'), 'Reaction forms need a no-script fallback');
    truthy($post !== false && str_contains($post, 'partials/reactions.php'), 'Post template must include reactions');
    truthy($plugin !== false && str_contains($plugin, "'/posts/{slug}/reaction'"), 'Reaction endpoint must be routed');
    truthy($controller !== false && str_contains($controller, 'Csrf::validate'), 'Reaction endpoint must validate CSRF');
    truthy($controller !== false && str_contains($controller, 'Set-Cookie'), 'Reaction endpoint must issue the visitor cookie');
    truthy($controller !== false && str_contains($controller, "wantsJson()"), 'Reaction endpoint must support JSON responses');
    truthy($view !== false && str_contains($view, "'reactions.css'"), 'Post pages must load reaction styles');
    truthy($view !== false && str_contains($view, "'reactions.js'"), 'Post pages must load reaction behavior');
    truthy($css !== false && str_contains($css, '.reaction-like.is-active'), 'Reaction active state must be themed');
    truthy($script !== false && str_contains($script, 'event.preventDefault()'), 'Reaction must not reload the page');
    truthy($script !== false && str_contains($script, 'optimistic'), 'Reaction must update optimistically');
    truthy($icons !== false && str_contains($icons, 'i-thumb-up'), 'Reaction needs a themed thumb icon');
});

check('Reaction totals reach admin and API', function (): void {
    $root = dirname(__DIR__);
    $admin = file_get_contents($root . '/plugins/admin/src/PostAdminController.php');
    $adminView = file_get_contents($root . '/themes/cream/templates/admin/posts.php');
    $api = file_get_contents($root . '/plugins/api/src/PostApiController.php');
    truthy($admin !== false && str_contains($admin, 'ReactionRepository'), 'Admin must load reaction totals');
    truthy($adminView !== false && str_contains($adminView, '赞 ') && str_contains($adminView, '踩 '), 'Admin list must show reaction totals');
    truthy($api !== false && str_contains($api, "'reactions'"), 'Post API detail must expose reaction totals');
});

check('Article lead is a labeled summary slip', function (): void {
    $root = dirname(__DIR__);
    $post = file_get_contents($root . '/themes/cream/templates/post.php');
    $posts = file_get_contents($root . '/public/assets/posts.css');
    truthy($post !== false && str_contains($post, 'class="post-lead"'), 'Article lead must use the summary slip');
    truthy($post !== false && str_contains($post, '文章概览'), 'Summary slip needs a visible label');
    truthy($post !== false && str_contains($post, 'post-lead-tape'), 'Summary slip needs a taped edge');
    truthy($posts !== false && str_contains($posts, '.post-lead-label'), 'Summary label must be themed');
    truthy($posts !== false && str_contains($posts, '--torn-edge: polygon('), 'Summary slip needs a torn edge');
    truthy($posts !== false && str_contains($posts, 'font-size: 12.5px') && str_contains($posts, 'font-size: 11.5px'), 'Summary must use smaller type than the article body');
    truthy($posts !== false && str_contains($posts, '.post-lead-text'), 'Summary body must use its own type style');
});

check('Article metadata pairs every value with an icon', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/post-meta.php');
    $post = file_get_contents($root . '/themes/cream/templates/post.php');
    $posts = file_get_contents($root . '/public/assets/posts.css');
    truthy($partial !== false && substr_count($partial, 'meta-item') >= 5, 'Metadata must expose five items');
    truthy($partial !== false && str_contains($partial, "cream_icon('calendar'"), 'Publish date needs an icon');
    truthy($partial !== false && str_contains($partial, "cream_icon('comment'"), 'Comment count needs an icon');
    truthy($partial !== false && str_contains($partial, 'cream_word_count'), 'Metadata must include computed word count');
    truthy($post !== false && str_contains($post, 'partials/post-meta.php'), 'Article must use the shared metadata component');
    truthy($posts !== false && str_contains($posts, '.meta-icon'), 'Metadata icons must be themed');
    truthy($posts !== false && str_contains($posts, '.meta-label { display: none; }'), 'Metadata labels must not consume space');
    truthy($posts !== false && str_contains($posts, '.post-meta .meta-item { min-height: 24px;'), 'Mobile metadata must stay compact');
});

check('Compressed assets match their source files', function (): void {
    $assets = dirname(__DIR__) . '/public/assets';
    foreach (glob($assets . '/*.gz') ?: [] as $gz) {
        $source = substr($gz, 0, -3);
        truthy(is_file($source), 'Missing source for ' . basename($gz));
        $raw = file_get_contents($source);
        $lines = gzfile($gz);
        truthy($raw !== false && is_array($lines), 'Unable to read compressed asset');
        $decoded = implode('', $lines);
        same($raw, $decoded, 'Compressed asset is stale: ' . basename($gz));
    }
});

echo PHP_EOL;
echo sprintf('%d tests, %d failures', $tests, $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
