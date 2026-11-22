<?php

declare(strict_types=1);

use Core\Auth\AdminRepository;
use Core\Auth\Auth;
use Core\Auth\LoginThrottle;
use Core\Audit\AuditPresenter;
use Core\Audit\AuditRepository;
use Core\Config\Env;
use Core\Content\Media;
use Core\Content\TagRepository;
use Core\Database\ConnectionInterface;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\RateLimitResult;
use Core\Http\RateLimitResponder;
use Core\Http\RequestGuard;
use Core\Http\FileRateLimiter;
use Core\Http\RateLimiterInterface;
use Core\Http\Response;
use Core\Http\Session;
use Core\Security\GraphicCaptcha;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Page\PageRepository;
use Core\Page\Pagination;
use Core\Api\ApiAuthenticator;
use Core\Api\ApiToken;
use Core\Api\ApiTokenRepository;
use Core\Status\SiteStatus;
use Core\Routing\Route;
use Core\Routing\Router;
use Core\Database\Migration;
use Core\Database\MigrationLoader;
use Core\Upgrade\ReleaseArchive;
use Core\Upgrade\ReleaseCatalog;
use Core\Upgrade\ReleaseManifest;
use Core\Upgrade\ReleaseRepository;
use Core\Upgrade\UpdateJob;
use Core\Upgrade\UpgradeStateStore;
use Core\Version\SchemaAhead;
use Core\Version\SchemaBehind;
use Core\Version\SchemaGuard;
use Core\Version\SchemaMissing;
use Core\Content\SearchTerm;
use Core\Seo\SitemapBuilder;
use Core\Seo\StructuredData;
use Core\Version\Version;
use Plugin\Admin\HtmlSanitizer;
use Plugin\Admin\MarkdownRenderer;
use Plugin\Admin\BodyRenderer;
use Plugin\Admin\ContentHealth;
use Core\Content\PostConflictException;
use Core\Content\PostDraftConflictException;
use Core\Content\PostDraftRepository;
use Core\Content\PostRevisionRepository;
use Core\Content\TextDiff;

require dirname(__DIR__) . '/bootstrap/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

final class RecordingRateLimiter implements RateLimiterInterface
{
    /** @var list<array{scope:string,identity:string,limit:int,window:int}> */
    public array $hits = [];

    public ?RateLimitResult $denied = null;

    public function hit(string $scope, string $identity, int $limit, int $window): RateLimitResult
    {
        $this->hits[] = ['scope' => $scope, 'identity' => $identity, 'limit' => $limit, 'window' => $window];
        if ($this->denied !== null) {
            return new RateLimitResult(
                $this->denied->allowed,
                $scope,
                $this->denied->limit,
                $this->denied->remaining,
                $this->denied->retryAfter,
            );
        }

        return RateLimitResult::permit($scope, $limit, max(0, $limit - 1), $window);
    }
}

final class TagAdminConnection implements ConnectionInterface
{
    /** @var list<array{sql:string,params:array<int|string,mixed>}> */
    public array $queries = [];

    /** @var list<array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,mixed>|null */
    public ?array $row = null;

    public int $affected = 0;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->rows;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->affected;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
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
use Core\Http\RateLimitResult;
use Core\Http\RateLimitResponder;
use Core\Http\RequestGuard;
use Core\Http\FileRateLimiter;
use Core\Http\RateLimiterInterface;
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
use Core\Http\RateLimitResult;
use Core\Http\RateLimitResponder;
use Core\Http\RequestGuard;
use Core\Http\FileRateLimiter;
use Core\Http\RateLimiterInterface;
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
use Core\Http\RateLimitResult;
use Core\Http\RateLimitResponder;
use Core\Http\RequestGuard;
use Core\Http\FileRateLimiter;
use Core\Http\RateLimiterInterface;
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

check('MarkdownRenderer renders safe blocks and keeps unsupported links as text', function (): void {
    $result = (new MarkdownRenderer())->render(
        "# 标题\n\n第一段\n第二段\n\n**加粗** *斜体* ~~删除~~ [好链接](https://example.com)\n\n" .
        "> 引用\n\n- 一\n- 二\n\n- [x] 完成\n- [ ] 待办\n\n" .
        "| 左 | 右 |\n| :--- | ---: |\n| 1 | 2 |\n\n```php\necho 1;\n```\n\n" .
        "<script>alert(1)</script>\n\n[坏链接](javascript:alert(1))"
    );
    truthy(str_contains($result['html'], '<h2>标题</h2>'), 'Heading must map to H2');
    truthy(str_contains($result['html'], '<p>第一段第二段</p>'), 'Chinese lines must join without a space');
    truthy(str_contains($result['html'], '<strong>加粗</strong>'), 'Strong emphasis missing');
    truthy(str_contains($result['html'], '<em>斜体</em>'), 'Italic emphasis missing');
    truthy(str_contains($result['html'], '<del>删除</del>'), 'Strikethrough missing');
    truthy(str_contains($result['html'], '<blockquote><p>引用</p></blockquote>'), 'Quote missing');
    truthy(str_contains($result['html'], '<table>') && str_contains($result['html'], 'align="right"'), 'Table alignment missing');
    truthy(str_contains($result['html'], 'class="language-php"'), 'Code language class missing');
    truthy(str_contains($result['html'], '☑ 完成') && str_contains($result['html'], '☐ 待办'), 'Task list markers missing');
    truthy(!str_contains($result['html'], '<script'), 'Raw script tag must be escaped');
    truthy(str_contains($result['html'], '&lt;script&gt;'), 'Raw script must remain visible as text');
    truthy(!str_contains($result['html'], 'href="javascript:'), 'Unsafe link must not become an anchor');
    truthy(str_contains($result['html'], '[坏链接](javascript:alert(1))'), 'Unsafe link should stay literal');
});

check('MarkdownRenderer can preserve heading levels for writing mode', function (): void {
    $html = (new MarkdownRenderer(1, 6))->render("# 一级\n\n#### 四级")['html'];
    truthy(str_contains($html, '<h1>一级</h1>') && str_contains($html, '<h4>四级</h4>'), 'Writing mode must keep heading levels');
});

check('MarkdownRenderer warns on unclosed fences and mismatched table columns', function (): void {
    $fence = (new MarkdownRenderer())->render("```html\n<span>没有闭合");
    truthy(str_contains($fence['html'], '&lt;span&gt;'), 'Unclosed fence content must be escaped');
    truthy(count($fence['warnings']) === 1 && str_contains($fence['warnings'][0], '代码块'), 'Expected an unclosed fence warning');
    $table = (new MarkdownRenderer())->render("| A | B |\n| --- | --- |\n| 1 | 2 | 3 |");
    truthy(str_contains($table['html'], '<table>'), 'Table must still render');
    truthy(count($table['warnings']) === 1 && str_contains($table['warnings'][0], '列数'), 'Expected a table column warning');
});

check('HtmlSanitizer can keep h1 only for writing mode', function (): void {
    truthy(str_contains((new HtmlSanitizer(true))->clean('<h1>一级</h1>'), '<h1>一级</h1>'), 'Writing sanitizer must allow h1');
    truthy(!str_contains((new HtmlSanitizer())->clean('<h1>一级</h1>'), '<h1>'), 'Default sanitizer must still unwrap h1');
});

check('BodyRenderer sanitizes HTML and reports removed markup', function (): void {
    $renderer = new BodyRenderer();
    $result = $renderer->render('html',
        '<p onclick="x()">你好</p><script>alert(1)</script>' .
        '<table><thead><tr><th>列</th></tr></thead><tbody><tr><td colspan="2">值</td></tr></tbody></table>' .
        '<code class="language-php">echo 1;</code><iframe src="https://evil.example"></iframe>'
    );
    truthy(str_contains($result['html'], '<p>你好</p>'), 'Safe paragraph missing');
    truthy(!str_contains($result['html'], 'onclick') && !str_contains($result['html'], '<script'), 'Unsafe HTML survived');
    truthy(str_contains($result['html'], '<table>') && str_contains($result['html'], 'colspan="2"'), 'Table attributes missing');
    truthy(str_contains($result['html'], '<code class="language-php">'), 'Code language class missing');
    truthy(!str_contains($result['html'], 'iframe'), 'Iframe survived');
    truthy(count($result['warnings']) === 1, 'Expected one sanitization warning');
});

check('BodyRenderer stats and headings read the rendered document', function (): void {
    $renderer = new BodyRenderer();
    $html = '<h2>第一节</h2><h3>第二节</h3><p>中文 English 123</p>';
    same([['level' => 2, 'text' => '第一节'], ['level' => 3, 'text' => '第二节']], $renderer->headings($html));
    $stats = $renderer->stats($html);
    truthy($stats['chars'] > 0 && $stats['words'] >= 3, 'Stats should count text and words');
    same(1, $stats['reading_minutes']);
});

check('PostConflictException carries the current row', function (): void {
    $current = ['id' => 9, 'content_version' => 4, 'updated_at' => '2026-11-21 10:00:00'];
    $exception = new PostConflictException($current);
    same($current, $exception->current);
    same('这篇文稿已被另一个窗口更新。', $exception->getMessage());
});

check('Editor migration and template preserve the dual-source contract', function (): void {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112103_add_editor_body_contract.php');
    truthy($migration !== false && str_contains($migration, 'body_format') && str_contains($migration, 'body_source') && str_contains($migration, 'content_version'), 'Migration must add the three editor columns');
    truthy(str_contains($migration, 'DROP COLUMN content_version') && str_contains($migration, 'DROP COLUMN body_source'), 'Migration must be reversible');
    truthy(str_contains($migration, 'posts_editor_rollback_backup') && str_contains($migration, 'REPLACE INTO posts_editor_rollback_backup'), 'Rollback must preserve editor source before dropping columns');
    truthy(str_contains($migration, 'JOIN posts_editor_rollback_backup') && str_contains($migration, 'p.body_source = b.body_source'), 'Upgrade must restore editor source after rollback');
    $template = file_get_contents($root . '/themes/cream/templates/admin/editor.php');
    truthy($template !== false && str_contains($template, 'name="body_source"') && str_contains($template, 'id="bodyHtml"'), 'Editor must expose both source editors');
    truthy(str_contains($template, 'data-ajax="off"'), 'Editor form must bypass the global AJAX replacement');
    truthy(is_file($root . '/public/assets/editor.css') && is_file($root . '/public/assets/editor.js'), 'Editor assets must exist');
});

check('Content health separates blockers, warnings and info', function (): void {
    $health = (new ContentHealth())->inspect([
        'title' => '短',
        'slug' => 'Bad Slug',
        'excerpt' => '',
        'body_format' => 'markdown',
        'body_source' => "# 标题\n\n```php\necho 1;\n",
        'body_html' => '<img src="/x">',
        'slug_exists' => true,
    ], ['表格列数不一致'], [['level' => 3, 'text' => '跳级']]);
    truthy(in_array('正文不能为空，补一段再发布。', $health['blockers'], true), 'Empty body must block publish');
    truthy(in_array('固定链接已被其他文章使用。', $health['blockers'], true), 'Duplicate slug must block publish');
    truthy(in_array('Markdown 代码围栏没有闭合，补上结尾的 ```。', $health['blockers'], true), 'Unclosed fence must block publish');
    truthy(count($health['warnings']) >= 5, 'Warnings must include title, headings, image, excerpt and table');
    same('blocker', $health['checks'][1]['level']);
    same('info', $health['checks'][8]['level']);
});

check('Content health passes a complete article', function (): void {
    $health = (new ContentHealth())->inspect([
        'title' => '一篇完整的手账',
        'slug' => 'weekend-journal',
        'excerpt' => '这是一个足够长的摘要，用来告诉读者这篇文章记录了什么，也方便列表页展示，让读者一眼知道内容重点。',
        'body_format' => 'markdown',
        'body_source' => "## 小节\n\n正文\n\n```php\necho 1;\n```\n",
        'body_html' => '<h2>小节</h2><p>正文</p><img src="/x.png" alt="示例">',
        'slug_exists' => false,
    ], [], [['level' => 2, 'text' => '小节']]);
    same([], $health['blockers']);
    same([], $health['warnings']);
});

check('Post draft repository decodes tags and reports conflicts', function (): void {
    $db = new FakeConnection([
        'post_id' => 3,
        'title' => '工作稿',
        'slug' => 'draft',
        'excerpt' => '',
        'tags_json' => '["生活","手账"]',
        'status' => 'draft',
        'body_format' => 'markdown',
        'body_source' => '# 工作稿',
        'body_html' => '<h2>工作稿</h2>',
        'base_version' => 3,
        'draft_version' => 2,
        'updated_at' => '2026-11-21 10:00:00',
    ]);
    $draft = (new PostDraftRepository($db))->find(3);
    truthy($draft !== null, 'Draft must be readable');
    same(['生活', '手账'], $draft['tags']);
    same(2, $draft['draft_version']);
    $exception = new PostDraftConflictException($draft);
    same(2, $exception->current['draft_version']);
});

check('Working draft migration and editor contract are wired', function (): void {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112104_add_post_drafts.php');
    truthy($migration !== false && str_contains($migration, 'CREATE TABLE IF NOT EXISTS post_drafts'), 'Missing post_drafts table');
    truthy(str_contains($migration, 'draft_version') && str_contains($migration, 'base_version'), 'Draft needs base and draft versions');
    truthy(str_contains($migration, 'DROP TABLE IF EXISTS post_drafts'), 'Draft migration must be reversible');
    $template = file_get_contents($root . '/themes/cream/templates/admin/editor.php');
    truthy($template !== false && str_contains($template, 'data-draft-url') && str_contains($template, 'name="draft_version"'), 'Editor must expose the draft endpoint and version');
    truthy(str_contains($template, '发布笔记') && str_contains($template, 'editorServerDraft'), 'Editor must separate publish from autosave');
    $script = file_get_contents($root . '/public/assets/editor.js');
    truthy($script !== false && str_contains($script, 'saveDraftNow') && str_contains($script, 'scheduleAutosave'), 'Editor script must autosave working drafts');
});

check('Text diff marks added and removed lines', function (): void {
    $diff = TextDiff::lines("第一行\n旧内容\n第三行", "第一行\n新内容\n第三行");
    $types = array_column($diff, 'type');
    truthy(in_array('remove', $types, true), 'Diff must mark removed line');
    truthy(in_array('add', $types, true), 'Diff must mark added line');
    same(1, count(array_keys($types, 'remove', true)));
    same(1, count(array_keys($types, 'add', true)));
    same([], TextDiff::lines('相同正文', '相同正文'));
});

check('Revision repository decodes tags and reads snapshots', function (): void {
    $db = new FakeConnection([
        'id' => 9,
        'post_id' => 3,
        'version' => 4,
        'title' => '发布版本',
        'slug' => 'release',
        'excerpt' => '',
        'tags_json' => '["生活","版本"]',
        'status' => 'published',
        'body_format' => 'markdown',
        'body_source' => '# 版本',
        'body_html' => '<h2>版本</h2>',
        'reading_minutes' => 2,
        'author_id' => 1,
        'published_at' => '2026-11-21 10:00:00',
        'created_at' => '2026-11-21 10:00:00',
    ]);
    $revision = (new PostRevisionRepository($db))->find(3, 4);
    truthy($revision !== null, 'Revision must be readable');
    same(['生活', '版本'], $revision['tags']);
    same(4, $revision['version']);
});

check('Revision migration and history page are wired', function (): void {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112105_add_post_revisions.php');
    truthy($migration !== false && str_contains($migration, 'CREATE TABLE IF NOT EXISTS post_revisions'), 'Missing post_revisions table');
    truthy(str_contains($migration, 'INSERT INTO post_revisions'), 'Existing posts must be backfilled');
    truthy(str_contains($migration, 'DROP TABLE IF EXISTS post_revisions'), 'Revision migration must be reversible');
    $controller = file_get_contents($root . '/plugins/admin/src/PostAdminController.php');
    truthy($controller !== false && substr_count($controller, 'PostRevisionRepository') >= 4, 'Publish and create must record revisions');
    $template = file_get_contents($root . '/themes/cream/templates/admin/revisions.php');
    truthy($template !== false && str_contains($template, '恢复为工作稿') && str_contains($template, 'diff-<?='), 'History page must compare and restore');
    truthy(str_contains($template, 'revisionCompare') && str_contains($template, '对比基准'), 'History page must allow choosing another revision as base');
    $revisionController = file_get_contents($root . '/plugins/admin/src/RevisionAdminController.php');
    truthy($revisionController !== false && str_contains($revisionController, "query()['compare']"), 'Revision controller must read compare target');
    $editor = file_get_contents($root . '/themes/cream/templates/admin/editor.php');
    truthy($editor !== false && str_contains($editor, '/revisions.html'), 'Editor must link to version history');
    truthy(str_contains($editor, 'data-editor-mode="write"') && str_contains($editor, 'id="editorRich"'), 'Editor must expose writing mode');
    truthy(!str_contains($editor, 'editorCommand'), 'Command palette must stay removed');
    $editorJs = file_get_contents($root . '/public/assets/editor.js');
    truthy($editorJs !== false && str_contains($editorJs, 'richToMarkdown') && str_contains($editorJs, 'runRichCommand'), 'Writing mode must serialize back to Markdown');
    truthy(!str_contains($editorJs, 'toggleCommandMenu'), 'Command palette must stay removed');
    $adminLayout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    truthy($adminLayout !== false && str_contains($adminLayout, 'admin-select.js'), 'Admin pages must load the self-drawn select layer');
    $selectJs = file_get_contents($root . '/public/assets/admin-select.js');
    truthy($selectJs !== false && str_contains($selectJs, 'aria-haspopup') && str_contains($selectJs, 'admin-select-menu'), 'Admin selects must render a custom paper menu');
    truthy(str_contains($editor, 'data-select-variant="status"') && str_contains($editor, 'data-hint="设为草稿'), 'Editor status must use the self-drawn status picker');
    truthy(substr_count($editor, 'data-editor-insert=') >= 14, 'Editor toolbar must expose the extended format set');
    truthy(str_contains($editor, 'data-syntax-markdown=') && str_contains($editor, 'data-syntax-html='), 'Toolbar buttons must carry mode-specific syntax hints');
    truthy(str_contains($editor, 'id="editorSyntaxText"'), 'Editor must explain the current syntax beside the toolbar');
    truthy(str_contains($editorJs, 'updateToolbarContext') && !str_contains($editorJs, "button.disabled = mode === 'html'"), 'Toolbar must stay enabled and switch syntax for HTML mode');
    truthy(str_contains($editorJs, "kind === 'ordered'") && str_contains($editorJs, "kind === 'inlinecode'") && str_contains($editorJs, "kind === 'image'"), 'Writing mode must support ordered lists, inline code and images');
    truthy(!str_contains($editorJs, 'window.prompt') && !str_contains($editorJs, 'window.confirm') && str_contains($editorJs, 'creamDialog'), 'Editor dialogs must stay self-drawn instead of native browser prompts');
    $postCss = file_get_contents($root . '/public/assets/posts.css');
    truthy($postCss !== false && str_contains($postCss, '.post-body table') && str_contains($postCss, '.post-body hr::after'), 'Body formatting must share the cream paper theme');
    truthy(str_contains($postCss, '.post-body pre code') && str_contains($postCss, 'white-space: pre'), 'Code blocks must reset inline-code chrome and keep source wrapping');
    truthy(str_contains($postCss, 'background: color-mix(in oklch, var(--cream-1) 82%, white 18%)'), 'Code blocks must use paper background instead of the old dark slab');
    $editorCss = file_get_contents($root . '/public/assets/editor.css');
    truthy($editorCss !== false && str_contains($editorCss, '.editor-prompt-card') && str_contains($editorCss, '.editor-format-tools { flex: 1 1 100%; order: 4; flex-wrap: wrap;'), 'Editor dialog and mobile toolbar must follow the paper theme');
});

check('Media URL uses stable id and disk basename', function (): void {
    $media = new Media(7, '2026/11/abcdef.png', '图.png', 'image/png', 100, 20, 10, '2026-11-20 00:00:00');
    same('/media/7/abcdef.png', $media->url());
});

check('TagRepository normalizes names and caps at eight', function (): void {
    $names = TagRepository::normalize([' 生活 ', '生活', '手账', '', 'A', 'a', '二', '三', '四', '五', '六', '七']);
    same(['生活', '手账', 'A', '二', '三', '四', '五', '六'], $names);
});


check('Tag admin queries filter, sort and count empty tags', function (): void {
    $connection = new TagAdminConnection();
    $connection->rows = [[
        'id' => 3,
        'name' => '手账',
        'slug' => 'shou-zhang',
        'post_count' => 6,
        'published_count' => 4,
        'created_at' => '2026-10-01 08:00:00',
        'last_used_at' => '2026-11-22 06:30:00',
    ]];
    $repository = new TagRepository($connection);
    $tags = $repository->adminPage(10, 20, ['q' => '手账', 'status' => 'empty', 'sort' => 'recent']);
    same(1, count($tags));
    same(6, $tags[0]['count']);
    same(4, $tags[0]['published_count']);
    $query = $connection->queries[0];
    truthy(str_contains($query['sql'], 'LIKE ?'), 'Search must use a bound LIKE');
    truthy(str_contains($query['sql'], 'HAVING post_count = 0'), 'Empty filter must use HAVING');
    truthy(str_contains($query['sql'], 'ORDER BY last_used_at DESC'), 'Recent sort must use last usage');
    truthy(str_contains($query['sql'], 'LIMIT 10 OFFSET 20'), 'Pagination must be bounded');
    same(['%手账%', '%手账%'], $query['params']);

    $connection->row = ['total' => 2];
    same(2, $repository->adminCount(['q' => '手账', 'status' => 'empty', 'sort' => 'recent']));
    $countQuery = $connection->queries[1];
    truthy(str_contains($countQuery['sql'], 'SELECT COUNT(*) AS total FROM ('), 'Count must use the filtered subquery');

    $connection->affected = 3;
    same(3, $repository->cleanupEmpty());
    truthy(str_contains($connection->queries[2]['sql'], 'LEFT JOIN post_tags'), 'Cleanup must only target unlinked tags');
});

check('Request parses raw JSON bodies without breaking older constructors', function (): void {
    $request = new Request('POST', '/api/v1/admin/tags', ['content-type' => 'application/json'], [], [], '{"name":"手账"}');
    same('{"name":"手账"}', $request->body());
    same(['name' => '手账'], $request->json());
    same(null, (new Request('POST', '/api/v1/admin/tags', [], []))->json());
    same(null, (new Request('POST', '/api/v1/admin/tags', [], [], [], '{bad'))->json());
});

check('Admin tag API, scopes and docs stay wired together', function (): void {
    $root = dirname(__DIR__);
    $plugin = file_get_contents($root . '/plugins/api/src/ApiPlugin.php');
    $scopes = file_get_contents($root . '/core/Api/ApiScope.php');
    $openapi = file_get_contents($root . '/docs/openapi.yaml');
    truthy($plugin !== false && str_contains($plugin, "'/api/v1/admin/tags'") && str_contains($plugin, "'/api/v1/admin/tags/{id}/merge'"), 'API plugin must register admin tag routes');
    truthy($plugin !== false && str_contains($plugin, "'PATCH'") && str_contains($plugin, "'DELETE'"), 'Admin tag API must support PATCH and DELETE');
    truthy($scopes !== false && str_contains($scopes, "'tags:read'") && str_contains($scopes, "'tags:write'"), 'Tag scopes must be selectable');
    truthy($openapi !== false && str_contains($openapi, '/api/v1/admin/tags/{id}/merge') && str_contains($openapi, 'AdminTagListMeta'), 'OpenAPI must document admin tags');
});


check('Admin dropdowns keep readable menus on narrow screens', function (): void {
    $root = dirname(__DIR__);
    $script = file_get_contents($root . '/public/assets/admin-select.js');
    $css = file_get_contents($root . '/public/assets/admin.css');
    $tags = file_get_contents($root . '/public/assets/admin-tags.css');
    truthy($script !== false && str_contains($script, 'Math.max(260, Math.ceil(rect.width))'), 'Dropdown menus must not copy narrow trigger widths');
    truthy($css !== false && str_contains($css, 'min-width: min(260px, calc(var(--admin-visible-width, 100vw) - 24px))'), 'Dropdown menus need a readable minimum width');
    truthy($css !== false && str_contains($css, '.admin-select-option-main { overflow-wrap: anywhere; white-space: normal;'), 'Long option labels must wrap instead of being clipped');
    truthy($tags !== false && str_contains($tags, 'grid-template-columns: 1fr;') && str_contains($tags, 'width: 100%;'), 'Tag filters must stack into one column on phones');
});

check('Admin pages fit the visual viewport when the browser is zoomed', function (): void {
    $root = dirname(__DIR__);
    $script = file_get_contents($root . '/public/assets/admin-viewport.js');
    $css = file_get_contents($root . '/public/assets/admin.css');
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    $select = file_get_contents($root . '/public/assets/admin-select.js');
    $posts = file_get_contents($root . '/public/assets/admin-posts.css');
    $editor = file_get_contents($root . '/public/assets/editor.css');
    $cream = file_get_contents($root . '/public/assets/cream.css');
    truthy($script !== false && str_contains($script, 'window.visualViewport'), 'Admin layout must observe the visual viewport');
    truthy($script !== false && str_contains($script, "classList.toggle('is-visually-zoomed'"), 'Admin layout must mark page zoom without changing normal rendering');
    truthy($script !== false && str_contains($script, "'--admin-fit-width'"), 'Admin layout must publish a fitted content width');
    truthy($script !== false && str_contains($script, "'--admin-right-inset'") && str_contains($script, "'--admin-bottom-inset'"), 'Fixed overlays need the visible viewport insets');
    truthy($css !== false && str_contains($css, 'html.is-visually-zoomed .admin-header-inner'), 'Admin container width must follow the fitted content width');
    truthy($css !== false && str_contains($css, 'html.is-visually-zoomed .nav-mobile-panel'), 'The mobile navigation drawer must stay inside the visible viewport');
    truthy($layout !== false && str_contains($layout, "cream_asset('admin-viewport.js')"), 'The layout must load the visual viewport helper');
    truthy($select !== false && str_contains($select, 'viewportBounds()') && str_contains($select, 'view.left + view.width'), 'Dropdown menus must stay inside the visual viewport');
    truthy($posts !== false && str_contains($posts, 'html.is-visually-zoomed .post-admin-bulk-bar'), 'Batch actions must stay inside the visible viewport');
    truthy($editor !== false && str_contains($editor, 'html.is-visually-zoomed .editor-mobile-bar'), 'The editor action bar must stay inside the visible viewport');
    truthy($cream !== false && str_contains($cream, 'html.is-visually-zoomed .cream-toast'), 'Toasts must stay inside the visible viewport');
});

check('Admin filter cards collapse on phones and reopen on demand', function (): void {
    $root = dirname(__DIR__);
    $script = file_get_contents($root . '/public/assets/admin-filters.js');
    $css = file_get_contents($root . '/public/assets/admin.css');
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    truthy($script !== false && str_contains($script, "form.classList.add('admin-filter-collapsible')"), 'Filter cards must opt into collapsible behaviour');
    truthy($script !== false && str_contains($script, "close.setAttribute('aria-label', '关闭筛选')"), 'Expanded filter cards need an accessible close button');
    truthy($script !== false && str_contains($script, "document.addEventListener('keydown'"), 'Escape must close an open filter card from anywhere on the page');
    truthy($script !== false && str_contains($script, 'card.close.focus({ preventScroll: true })'), 'Opening a filter card must move focus to its close button');
    truthy($css !== false && str_contains($css, '.admin-filter-collapsible:not(.is-filter-open) { display: none !important; }'), 'Filter cards must not reserve vertical space while closed');
    truthy($layout !== false && str_contains($layout, "cream_asset('admin-filters.js')"), 'The layout must load the filter toggle script');
    foreach (['posts', 'tags', 'audit'] as $page) {
        $template = file_get_contents($root . '/themes/cream/templates/admin/' . $page . '.php');
        truthy($template !== false && str_contains($template, 'data-admin-filter-panel'), 'Missing filter panel hook on ' . $page);
    }
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

check('Graphic captcha is one-time, case tolerant and attempt limited', function (): void {
    $_SESSION = [];
    $captcha = new GraphicCaptcha(ttl: 300, maxAttempts: 5);
    $code = $captcha->issue();
    truthy(preg_match('/^[2346789ABCDEFGHJKLMNPQRSTUVWXYZ]{5}$/', $code) === 1, 'Captcha code must use the unambiguous five-character alphabet');
    $state = $_SESSION[GraphicCaptcha::SESSION_KEY] ?? [];
    truthy(is_array($state) && !isset($state['code']) && !in_array($code, $state, true), 'Captcha answer must not be stored in plaintext');
    truthy(($state['expires'] ?? 0) > time() && ($state['tries'] ?? -1) === 0, 'Captcha state must start with a future expiry and zero tries');
    truthy($captcha->verify(strtolower($code)), 'Captcha answer must be case tolerant');
    truthy(!$captcha->verify($code), 'Captcha answer was accepted twice');

    $code = $captcha->issue();
    $_SESSION[GraphicCaptcha::SESSION_KEY]['expires'] = time() - 1;
    truthy(!$captcha->verify($code), 'Expired captcha answer accepted');
    truthy(!isset($_SESSION[GraphicCaptcha::SESSION_KEY]), 'Expired captcha state must be cleared');

    $limited = new GraphicCaptcha(ttl: 300, maxAttempts: 3);
    $limited->issue();
    truthy(!$limited->verify('00000'), 'Wrong captcha answer accepted');
    truthy(!$limited->verify('00000'), 'Wrong captcha answer accepted on second try');
    truthy(!$limited->verify('00000'), 'Wrong captcha answer accepted on third try');
    truthy(!isset($_SESSION[GraphicCaptcha::SESSION_KEY]), 'Captcha state must be cleared after the attempt limit');
    truthy(!$limited->verify('00000'), 'Captcha state must not be reusable after the attempt limit');
});

check('Graphic captcha renders a local PNG', function (): void {
    $captcha = new GraphicCaptcha();
    $png = $captcha->image('K7M2P');
    truthy(str_starts_with($png, "\x89PNG\r\n\x1a\n"), 'Captcha image must be a PNG');
    $size = getimagesizefromstring($png);
    truthy(is_array($size), 'Captcha PNG metadata is unreadable');
    same(160, $size[0], 'Captcha width changed');
    same(50, $size[1], 'Captcha height changed');
    same('image/png', $size['mime'], 'Captcha MIME type changed');
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

check('Highlighter marks current navigation and article quotes', function (): void {
    $root = dirname(__DIR__);
    $cream = file_get_contents($root . '/public/assets/cream.css');
    $posts = file_get_contents($root . '/public/assets/posts.css');
    truthy($cream !== false && str_contains($cream, '--marker-wash'), 'Missing marker color token');
    truthy($cream !== false && str_contains($cream, '--marker-cut'), 'Missing marker edge token');
    truthy($cream !== false && str_contains($cream, '.site-header .nav-link.is-current::before'), 'Current navigation needs a marker layer');
    truthy($cream !== false && str_contains($cream, '.site-header .nav-drawer-link.is-current .nav-drawer-text::before'), 'Drawer current item needs a marker layer');
    truthy($posts !== false && str_contains($posts, '.post-body blockquote::before'), 'Article quotes need a marker layer');
    truthy($cream !== false && str_contains($cream, '@media (prefers-reduced-motion: reduce)'), 'Marker animation needs a reduced-motion fallback');
});

check('Short pages pin the footer to the viewport bottom', function (): void {
    $root = dirname(__DIR__);
    $cream = file_get_contents($root . '/public/assets/cream.css');
    truthy(
        $cream !== false && str_contains($cream, "body {\n  margin: 0;\n  min-height: 100vh;\n  display: flex;\n  flex-direction: column;"),
        'The page body must be a vertical flex column'
    );
    truthy(
        $cream !== false && str_contains($cream, ".site-main {\n  flex: 1 0 auto;"),
        'The main region must grow so short pages keep the footer at the bottom'
    );
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

check('Footer sits close to content and groups its links', function (): void {
    $root = dirname(__DIR__);
    $footer = file_get_contents($root . '/themes/cream/templates/partials/site-footer.php');
    $css = file_get_contents($root . '/public/assets/cream.css');
    truthy($footer !== false && str_contains($footer, 'footer-brand') && str_contains($footer, 'footer-links'), 'Footer needs a brand block and a link group');
    truthy($footer !== false && str_contains($footer, 'footer-product') && str_contains($footer, 'footer-meta'), 'Footer version line needs its own row');
    truthy($css !== false && str_contains($css, 'padding: 38px 0 24px;'), 'Content must not reserve a large blank above the footer');
    truthy($css !== false && str_contains($css, 'grid-template-areas:') && str_contains($css, '"brand links"'), 'Desktop footer must use a compact two-row grid');
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
    truthy($post !== false && str_contains($post, 'partials/post-receipt.php'), 'Post template must include the reader receipt');
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
    $adminView = file_get_contents($root . '/themes/cream/templates/admin/posts.php')
        . file_get_contents($root . '/themes/cream/templates/admin/partials/posts-table.php')
        . file_get_contents($root . '/themes/cream/templates/admin/partials/posts-cards.php');
    $api = file_get_contents($root . '/plugins/api/src/PostApiController.php');
    truthy($admin !== false && str_contains($admin, 'ReactionRepository'), 'Admin must load reaction totals');
    truthy($adminView !== false && str_contains($adminView, '赞 ') && str_contains($adminView, '踩 '), 'Admin list must show reaction totals');
    truthy($api !== false && str_contains($api, "'reactions'"), 'Post API detail must expose reaction totals');
});

check('Search terms are cleaned and shortened', function (): void {
    same('手账 建站', SearchTerm::normalize(' <b>手账</b>   建站 '));
    same('', SearchTerm::normalize(null));
    same('', SearchTerm::normalize('   '));
    same(SearchTerm::MAX_LENGTH, mb_strlen(SearchTerm::normalize(str_repeat('字', 80)), 'UTF-8'));
    truthy(SearchTerm::isShort('字'), 'One character must use the LIKE fallback');
    truthy(!SearchTerm::isShort('手账'), 'Two characters must use the full-text index');
});

check('Search index migration keeps the ngram contract', function (): void {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112007_add_post_search_index.php');
    truthy($migration !== false && str_contains($migration, 'ALTER TABLE posts'), 'Search index migration must alter posts');
    truthy($migration !== false && str_contains($migration, 'FULLTEXT KEY ft_posts_search'), 'Search index must use the expected key');
    truthy($migration !== false && str_contains($migration, 'WITH PARSER ngram'), 'Search index must use the ngram parser');
    truthy($migration !== false && str_contains($migration, 'DROP INDEX ft_posts_search'), 'Search index migration must be reversible');
});

check('Search overlay, redirect route and API are wired', function (): void {
    $root = dirname(__DIR__);
    $overlay = file_get_contents($root . '/themes/cream/templates/partials/search-overlay.php');
    $controller = file_get_contents($root . '/plugins/posts/src/SearchController.php');
    $repository = file_get_contents($root . '/core/Content/PostRepository.php');
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $layout = file_get_contents($root . '/themes/cream/templates/layout.php');
    $api = file_get_contents($root . '/plugins/api/src/PostApiController.php');
    $css = file_get_contents($root . '/public/assets/search-overlay.css');
    $script = file_get_contents($root . '/public/assets/search-overlay.js');
    truthy(!is_file($root . '/themes/cream/templates/search.php'), 'Standalone search page must be removed');
    truthy(!is_file($root . '/public/assets/search.css'), 'Standalone search stylesheet must be removed');
    truthy($controller !== false && str_contains($controller, 'Response::redirect'), 'Old search URL must redirect to the overlay entry');
    truthy($controller !== false && str_contains($controller, "'/?search='"), 'Search redirect must carry the query to the home overlay');
    truthy($controller !== false && !str_contains($controller, 'theme->render'), 'Search controller must not render a standalone page');
    truthy($repository !== false && str_contains($repository, 'MATCH(title, excerpt, body)'), 'Search must use the full-text index');
    truthy($repository !== false && str_contains($repository, 'LIKE ? OR excerpt LIKE ?'), 'Single-character search must fall back to LIKE');
    truthy($header !== false && str_contains($header, 'data-search-open'), 'Header needs a search trigger');
    truthy($header !== false && str_contains($header, 'header-actions'), 'Search trigger must sit in header actions');
    truthy($header !== false && !str_contains($header, 'href="/search"'), 'Header must not link to a standalone search page');
    truthy($header !== false && strpos($header, 'data-search-open') !== false && strpos($header, 'class="nav-mobile"') !== false && strpos($header, 'data-search-open') < strpos($header, 'class="nav-mobile"'), 'Search trigger must be left of the mobile sidebar button');
    truthy($overlay !== false && str_contains($overlay, 'data-search-input') && str_contains($overlay, 'data-search-results'), 'Overlay must contain an input and results region');
    truthy($overlay !== false && str_contains($overlay, 'role="searchbox"') && !str_contains($overlay, 'type="search"'), 'Search field must avoid the native clear affordance');
    truthy($overlay !== false && str_contains($overlay, '没有翻到这一页'), 'Overlay needs an empty result state');
    truthy($layout !== false && str_contains($layout, 'search-overlay.css') && str_contains($layout, 'search-overlay.js'), 'Layout must load overlay assets');
    truthy($api !== false && str_contains($api, 'public function search'), 'Search API method must exist');
    truthy($script !== false && str_contains($script, '/api/v1/search'), 'Overlay must query the search API');
    truthy($script !== false && str_contains($script, 'AbortController') && str_contains($script, 'localStorage') && str_contains($script, 'ArrowDown') && str_contains($script, 'Escape'), 'Overlay must cancel requests and support keyboard search');
    truthy($css !== false && str_contains($css, '.search-overlay-panel') && str_contains($css, 'backdrop-filter'), 'Overlay needs a masked floating panel');
    truthy($css !== false && str_contains($css, '::-webkit-search-cancel-button') && str_contains($css, 'appearance: none'), 'Search field must hide the native clear button');
    truthy(is_file($root . '/docs/SEARCH.md'), 'Search documentation must exist');
});

check('Navigation toggle shows only one icon', function (): void {
    $root = dirname(__DIR__);
    $css = file_get_contents($root . '/public/assets/cream.css');
    truthy($css !== false && str_contains($css, '.nav-icon-open { display: grid; }'), 'Open icon must be visible');
    truthy($css !== false && str_contains($css, '.nav-icon-close { display: none; }'), 'Close icon must start hidden');
    truthy($css !== false && !str_contains($css, '.nav-mobile summary > span { display: grid;'), 'Generic span display must not override icon state');
});

check('Mobile navigation uses a torn right drawer', function (): void {
    $root = dirname(__DIR__);
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $css = file_get_contents($root . '/public/assets/cream.css');
    $js = file_get_contents($root . '/public/assets/cream.js');
    truthy($header !== false && str_contains($header, 'nav-mobile-sheet'), 'Mobile nav needs a paper sheet');
    truthy($header !== false && substr_count($header, 'nav-drawer-link') >= 6, 'Mobile nav needs six drawer links');
    truthy($header !== false && str_contains($header, 'nav-mobile-scrim'), 'Mobile nav needs a dismiss scrim');
    truthy($css !== false && str_contains($css, '--drawer-tear: polygon('), 'Drawer needs a torn edge');
    truthy($css !== false && str_contains($css, 'clip-path: var(--drawer-tear)'), 'Drawer sheet must use the torn edge');
    truthy($js !== false && str_contains($js, 'nav-open') && str_contains($js, "event.key !== 'Escape'"), 'Drawer needs scroll lock and Escape dismiss');
});

check('Article back link is a themed paper tab', function (): void {
    $root = dirname(__DIR__);
    $posts = file_get_contents($root . '/public/assets/posts.css');
    truthy($posts !== false && str_contains($posts, '.back-link .icon'), 'Back link needs a themed icon badge');
    truthy($posts !== false && str_contains($posts, 'border-radius: 5px 11px 9px 6px'), 'Back link needs a paper-tab shape');
    truthy($posts !== false && str_contains($posts, 'transform: rotate(-0.35deg)'), 'Back link needs a slight paper tilt');
    truthy($posts !== false && str_contains($posts, '.back-link:focus-visible'), 'Back link needs a keyboard focus ring');
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

check('Admin login entry lives in the backstage menu', function (): void {
    $root = dirname(__DIR__);
    $footer = file_get_contents($root . '/themes/cream/templates/partials/site-footer.php');
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    truthy($footer !== false && !str_contains($footer, 'admin/login'), 'Public footer must not expose an admin login link');
    truthy($header !== false && !str_contains($header, 'admin/login'), 'Public navigation must not expose an admin login link');
    truthy($layout !== false && str_contains($layout, '后台登录导航') && str_contains($layout, 'href="/admin/login.html"'), 'Backstage menu must carry the login entry');
    truthy($layout !== false && str_contains($layout, '后台登录移动导航'), 'Backstage mobile menu must carry the login entry');
});

check('API docs are private and admin-only', function (): void {
    $root = dirname(__DIR__);
    $plugin = file_get_contents($root . '/plugins/admin/src/AdminPlugin.php');
    $controller = file_get_contents($root . '/plugins/admin/src/ApiDocsAdminController.php');
    $template = file_get_contents($root . '/themes/cream/templates/admin/api-docs.php');
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $footer = file_get_contents($root . '/themes/cream/templates/partials/site-footer.php');
    $home = file_get_contents($root . '/themes/cream/templates/home.php');
    $status = file_get_contents($root . '/themes/cream/templates/status.php');
    truthy(!is_file($root . '/public/api.html'), 'Public API docs page must be removed');
    truthy(!is_file($root . '/public/docs/openapi.yaml'), 'OpenAPI source must not stay under the web root');
    truthy(is_file($root . '/docs/openapi.yaml'), 'OpenAPI source must live outside the web root');
    truthy($plugin !== false && str_contains($plugin, "'/admin/api-docs'") && str_contains($plugin, "'/admin/api-docs/openapi.yaml'"), 'Admin plugin must expose guarded docs routes');
    truthy($controller !== false && str_contains($controller, 'new AdminGuard(') && substr_count($controller, 'authenticate($request)') >= 2, 'Both docs routes must require an admin session');
    truthy($controller !== false && str_contains($controller, "'/docs/openapi.yaml'") && str_contains($controller, "'Cache-Control', 'no-store, private, max-age=0'"), 'Private YAML must be read from outside public and must not be cached');
    truthy($template !== false && str_contains($template, 'data-url="/admin/api-docs/openapi.yaml"'), 'Docs page must load the guarded YAML endpoint');
    truthy($layout !== false && str_contains($layout, 'href="/admin/api-docs.html"'), 'Backstage menu must link the private docs page');
    foreach ([$header, $footer, $home, $status] as $publicTemplate) {
        truthy($publicTemplate !== false && !str_contains($publicTemplate, '/api.html'), 'Public templates must not link the removed docs page');
    }
});

check('Page headings live in the top bar', function (): void {
    $root = dirname(__DIR__);
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $home = file_get_contents($root . '/themes/cream/templates/home.php');
    $posts = file_get_contents($root . '/themes/cream/templates/posts.php');
    $post = file_get_contents($root . '/themes/cream/templates/post.php');
    $archive = file_get_contents($root . '/themes/cream/templates/archive.php');
    $adminLayout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    $adminPosts = file_get_contents($root . '/themes/cream/templates/admin/posts.php');
    truthy($header !== false && str_contains($header, 'brand-page-title') && str_contains($header, '<h1 class="brand-text"'), 'Public page heading must render in the top bar');
    truthy($home !== false && str_contains($home, 'hero-title') && !str_contains($home, '<h1'), 'Home hero must not compete with the top-bar heading');
    truthy($posts !== false && !str_contains($posts, '<h1') && $post !== false && !str_contains($post, '<h1') && $archive !== false && !str_contains($archive, '<h1'), 'List and article headings must move out of content');
    truthy($adminLayout !== false && str_contains($adminLayout, 'admin-brand-title'), 'Admin page heading must render in the top bar');
    truthy($adminPosts !== false && !str_contains($adminPosts, '<h1'), 'Admin page heading must not repeat in content');
});

check('Admin login uses a taped desk layout', function (): void {
    $root = dirname(__DIR__);
    $login = file_get_contents($root . '/themes/cream/templates/admin/login.php');
    $css = file_get_contents($root . '/public/assets/admin.css');
    $plugin = file_get_contents($root . '/plugins/admin/src/AdminPlugin.php');
    $env = file_get_contents($root . '/.env.example');
    truthy($login !== false && str_contains($login, 'admin-auth-layout'), 'Login needs the two-column desk layout');
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    truthy($layout !== false && str_contains($layout, "admin-login-body"), 'Login page needs its own body class');
    truthy($login !== false && str_contains($login, 'admin-auth-note'), 'Login needs the private desk note');
    truthy($login !== false && substr_count($login, 'tape-field') >= 2 && substr_count($login, 'tape-input') >= 2, 'Both credentials must use tape fields');
    truthy($login !== false && str_contains($login, 'type="password"'), 'Password must remain a real password input');
    truthy($login !== false && str_contains($login, 'admin-captcha'), 'Login needs the local graphic captcha panel');
    truthy($login !== false && str_contains($login, 'src="/admin/captcha"') && str_contains($login, 'name="captcha"') && str_contains($login, 'admin-captcha-field'), 'Login must place the local captcha image inside the answer field');
    truthy($login !== false && str_contains($login, 'data-captcha-refresh'), 'Captcha needs a refresh control');
    truthy($login !== false && !str_contains($login, 'challenges.cloudflare.com') && !str_contains($login, 'cf-turnstile'), 'Login must not load Cloudflare Turnstile');
    truthy($plugin !== false && str_contains($plugin, "'/admin/captcha'"), 'Admin routes must expose the captcha image endpoint');
    $controller = file_get_contents($root . '/plugins/admin/src/LoginController.php');
    truthy($controller !== false && str_contains($controller, "'Cross-Origin-Resource-Policy', 'same-origin'") && str_contains($controller, "'X-Robots-Tag', 'noindex, nofollow'"), 'Captcha image must forbid cross-origin reuse and indexing');
    truthy($env !== false && !str_contains($env, 'TURNSTILE_'), 'Environment example must not advertise Turnstile keys');
    truthy(!is_file($root . '/core/Security/Turnstile.php'), 'Turnstile implementation must be removed');
    truthy($css !== false && str_contains($css, '.admin-captcha-field') && str_contains($css, '.admin-captcha-refresh img') && str_contains($css, '.admin-captcha-field:focus-within'), 'Captcha image must sit inside the answer field with a visible focus state');
    truthy($css !== false && str_contains($css, 'grid-template-columns: minmax(0, 0.88fr) minmax(390px, 1.12fr)'), 'Login desk must be split into two columns');
    truthy($css !== false && str_contains($css, '.tape-input::before') && str_contains($css, 'clip-path: polygon(1% 0'), 'Credential fields need a torn tape surface');
    truthy($css !== false && str_contains($css, 'appearance: none') && preg_match('/\.tape-input input:focus \{[^}]*background: transparent;[^}]*box-shadow: none;/s', $css) === 1, 'Credential inputs must keep the tape surface when focused');
    truthy($css !== false && !str_contains($css, 'width: min(430px'), 'Login must no longer use the old single narrow card');
    truthy($css !== false && str_contains($css, '.admin-login-body .admin-main') && str_contains($css, 'flex: 1 1 auto'), 'Login main must fill the remaining viewport');
    truthy($css !== false && str_contains($css, '.admin-login-body .admin-auth') && str_contains($css, 'overflow: hidden'), 'Login page must not scroll');
    truthy($css !== false && str_contains($css, '@media (max-height: 820px)'), 'Short screens need a compact login layout');
    truthy($css !== false && str_contains($css, '.admin-captcha-answer input') && str_contains($css, '.admin-captcha-refresh') && !str_contains($css, '.admin-captcha-row'), 'Captcha answer and image need the embedded field styles');
    truthy(str_contains($css, 'min-width: 0') && str_contains($css, 'max-width: 480px'), 'Login grid must be allowed to shrink on narrow phones');
    truthy(str_contains($css, '.admin-login-body { height: auto; min-height: 100dvh; overflow-x: hidden; overflow-y: auto; }'), 'Login page must scroll vertically on phones');
});

check('Comments start with three and reveal in batches', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/comments.php');
    $script = file_get_contents($root . '/public/assets/comments.js');
    $css = file_get_contents($root . '/public/assets/comments.css');
    truthy($partial !== false && str_contains($partial, 'data-comment-list'), 'Comment list needs a reveal target');
    truthy($partial !== false && str_contains($partial, 'data-comment-thread'), 'Each top-level comment must be a revealable thread');
    truthy($partial !== false && str_contains($partial, 'data-comment-more hidden'), 'Load-more control must start hidden for no-script fallback');
    truthy($script !== false && str_contains($script, 'const pageSize = 3;') && str_contains($script, 'commentMore.hidden = false;'), 'Comments must reveal three at a time');
    truthy($script !== false && str_contains($script, 'thread.hidden = index >= visible;'), 'Hidden comment threads must be removed from view');
    truthy($css !== false && str_contains($css, '.comment-more[hidden] { display: none; }') && str_contains($css, '.comment-more-button'), 'Load-more control must use the paper tab style');
});

check('Admin mobile menu uses a torn drawer', function (): void {
    $root = dirname(__DIR__);
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    $css = file_get_contents($root . '/public/assets/admin.css');
    truthy($layout !== false && str_contains($layout, 'admin-mobile'), 'Admin header needs a mobile drawer');
    truthy($layout !== false && substr_count($layout, 'nav-drawer-link') >= 6, 'Admin drawer needs six destinations');
    truthy($layout !== false && str_contains($layout, 'nav-drawer-foot') && str_contains($layout, 'admin-mobile-logout'), 'Admin drawer needs a logout footer');
    truthy($layout !== false && str_contains($layout, 'nav-icon-close'), 'Admin drawer needs a single close icon state');
    truthy($css !== false && str_contains($css, '.admin-nav.nav-desktop { display: none; }'), 'Desktop admin nav must hide on mobile');
    truthy($css !== false && str_contains($css, '.admin-mobile { display: block; }'), 'Admin drawer must show on mobile');
    truthy($css !== false && str_contains($css, '.admin-mobile-scrim, .admin-mobile-panel { top: 59px; }'), 'Admin drawer must start below the shorter header');
    truthy($css !== false && !str_contains($css, 'backdrop-filter: blur(12px)'), 'Admin header must not trap fixed drawers');
});

check('RSS feed publishes escaped posts with absolute links', function (): void {
    $posts = [
        new \Core\Content\Post(
            1,
            'hello',
            'A & <B>',
            '摘要 <script>',
            '<p>body</p>',
            '2026-11-20 09:30:00',
            '2026-11-20 09:30:00',
            3,
            ['手账', '代码'],
        ),
    ];
    $xml = \Core\Content\FeedBuilder::render(
        $posts,
        ['title' => '日记 & Planner', 'description' => '安静写字', 'language' => 'zh-CN', 'timezone' => 'Asia/Shanghai'],
        'https://example.com/',
        '0.1.22',
    );
    truthy(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>'), 'Feed must be XML');
    truthy(str_contains($xml, '<rss version="2.0"'), 'Feed must use RSS 2.0');
    truthy(str_contains($xml, '<link>https://example.com/posts/hello.html</link>'), 'Feed items need absolute links');
    truthy(str_contains($xml, 'A &amp; &lt;B&gt;'), 'Feed titles must be XML-escaped');
    truthy(str_contains($xml, '摘要 &lt;script&gt;'), 'Feed excerpts must be escaped');
    truthy(str_contains($xml, '<category>手账</category>'), 'Feed should carry post tags');
    truthy(str_contains($xml, 'xmlns:atom="http://www.w3.org/2005/Atom"'), 'Feed should advertise its self link');
    truthy(str_contains($xml, '<content:encoded><![CDATA[<p>body</p>]]></content:encoded>'), 'RSS should carry full post content');
});

check('Atom and JSON Feed publish the same posts', function (): void {
    $root = dirname(__DIR__);
    $posts = [
        new \Core\Content\Post(
            1,
            'hello',
            'A & <B>',
            '摘要 <script>',
            '<p>body</p>',
            '2026-11-20 09:30:00',
            '2026-11-20 10:00:00',
            3,
            ['手账', '代码'],
        ),
    ];
    $site = ['title' => '日记 & Planner', 'description' => '安静写字', 'language' => 'zh-CN', 'timezone' => 'Asia/Shanghai'];

    $atom = \Core\Content\FeedBuilder::atom($posts, $site, 'https://example.com', '0.1.35');
    truthy(str_contains($atom, '<feed xmlns="http://www.w3.org/2005/Atom"'), 'Atom must use the Atom namespace');
    truthy(str_contains($atom, 'href="https://example.com/atom.xml" rel="self"') || str_contains($atom, 'rel="self" type="application/atom+xml" href="https://example.com/atom.xml"'), 'Atom must advertise its self link');
    truthy(str_contains($atom, '<content type="html">&lt;p&gt;body&lt;/p&gt;</content>'), 'Atom should escape full post content');
    truthy(str_contains($atom, '<category term="手账"/>'), 'Atom should carry post tags');

    $json = \Core\Content\FeedBuilder::json($posts, $site, 'https://example.com', '0.1.35');
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    truthy(is_array($data), 'JSON Feed must decode');
    if (is_array($data)) {
        same('https://jsonfeed.org/version/1.1', $data['version'] ?? null);
        truthy(($data['items'][0]['content_html'] ?? '') === '<p>body</p>', 'JSON Feed should carry full post content');
        truthy(($data['items'][0]['tags'] ?? []) === ['手账', '代码'], 'JSON Feed should carry post tags');
    }

    $layout = file_get_contents($root . '/themes/cream/templates/layout.php');
    $plugin = file_get_contents($root . '/plugins/posts/src/PostsPlugin.php');
    truthy($layout !== false && str_contains($layout, 'application/atom+xml') && str_contains($layout, 'application/feed+json'), 'Layout needs Atom and JSON discovery links');
    truthy($plugin !== false && str_contains($plugin, "'/atom.xml'") && str_contains($plugin, "'/feed.json'"), 'Posts plugin must route all feed formats');
    truthy(is_file($root . '/docs/api/feeds.md'), 'Feed documentation must exist');
});

check('Feed and SEO endpoints skip sessions', function (): void {
    $root = dirname(__DIR__);
    $index = file_get_contents($root . '/public/index.php');
    $controller = file_get_contents($root . '/plugins/posts/src/ArchiveController.php');
    truthy($index !== false && str_contains($index, '$statelessPaths'), 'Front controller needs a stateless path list');
    truthy($index !== false && str_contains($index, "'/feed.xml'") && str_contains($index, "'/sitemap.xml'"), 'Feed and sitemap must be stateless');
    $session = $index === false ? false : strpos($index, 'Session::start()');
    $guard = $index === false ? false : strpos($index, 'if (!$stateless)');
    truthy($session !== false && $guard !== false && $guard < $session, 'Session startup must be skipped for stateless endpoints');
    truthy($index !== false && str_contains($index, "str_starts_with(\$path, '/archive/')"), 'Archive pages must be stateless too');
    truthy($index !== false && str_contains($index, "'/archive.html'"), 'Archive index HTML alias must be stateless');
    truthy($controller !== false && str_contains($controller, "'Cache-Control'") && str_contains($controller, "'public, max-age=300'"), 'Archive pages need explicit public caching');
});

check('Archive index and period pages are wired', function (): void {
    $root = dirname(__DIR__);
    $repository = file_get_contents($root . '/core/Content/PostRepository.php');
    $controller = file_get_contents($root . '/plugins/posts/src/ArchiveController.php');
    $plugin = file_get_contents($root . '/plugins/posts/src/PostsPlugin.php');
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    $archive = file_get_contents($root . '/themes/cream/templates/archive.php');
    $year = file_get_contents($root . '/themes/cream/templates/archive-year.php');
    $month = file_get_contents($root . '/themes/cream/templates/archive-month.php');
    $css = file_get_contents($root . '/public/assets/archive.css');
    $seo = file_get_contents($root . '/plugins/web/src/SeoController.php');
    truthy($repository !== false && str_contains($repository, 'archiveMonths'), 'Archive repository needs month aggregates');
    truthy($repository !== false && str_contains($repository, 'publishedInPeriod') && str_contains($repository, 'countPublishedInPeriod'), 'Archive repository needs period reads and counts');
    truthy($controller !== false && str_contains($controller, 'Pagination::resolve'), 'Archive pages must reuse the shared ten-item pager');
    truthy($controller !== false && str_contains($controller, 'monthPageUrl'), 'Archive controller needs canonical page URLs');
    truthy($plugin !== false && str_contains($plugin, "'/archive'") && str_contains($plugin, "'/archive/{year}/{month}/page/{page}'"), 'Posts plugin must route archive pages');
    truthy($header !== false && str_contains($header, 'href="/archive.html"'), 'Navigation needs an archive link');
    truthy($archive !== false && str_contains($archive, 'archive-month-grid'), 'Archive overview needs month links');
    truthy($year !== false && str_contains($year, 'archive-line'), 'Year archive needs article rows');
    truthy($month !== false && str_contains($month, 'page-pager'), 'Month archive needs pagination');
    truthy($css !== false && str_contains($css, '.archive-ledger') && str_contains($css, '.archive-pagination'), 'Archive styles must cover ledger and pagination');
    truthy($seo !== false && str_contains($seo, '/archive.html') && str_contains($seo, "archiveMonths"), 'Sitemap must include archive pages');
    truthy(is_file($root . '/docs/ARCHIVE.md'), 'Archive documentation must exist');
});

check('Pagination keeps every listing at ten items', function (): void {
    $state = Pagination::resolve(3, 28);
    same(10, $state['per_page']);
    same(3, $state['pages']);
    same(3, $state['page']);
    same(20, $state['offset']);
    same([1, 2, 3], Pagination::window(2, 3));

    $root = dirname(__DIR__);
    foreach ([
        'plugins/posts/src/PostListController.php',
        'plugins/posts/src/ArchiveController.php',
        'plugins/posts/src/TagController.php',
    ] as $file) {
        $source = file_get_contents($root . '/' . $file);
        truthy($source !== false && str_contains($source, 'Pagination::resolve'), $file . ' must use the shared ten-item pager');
    }
    foreach ([
        'plugins/admin/src/PostAdminController.php',
        'plugins/admin/src/CommentAdminController.php',
        'plugins/admin/src/MediaAdminController.php',
        'plugins/admin/src/TagAdminController.php',
        'plugins/admin/src/RevisionAdminController.php',
        'plugins/admin/src/AuditAdminController.php',
    ] as $file) {
        $source = file_get_contents($root . '/' . $file);
        truthy($source !== false && (str_contains($source, '$limit = 10') || str_contains($source, 'Pagination::resolve') || str_contains($source, 'private const PAGE_SIZES = [10]')), $file . ' must cap a page at ten items');
    }
});

check('Article share card, RSS and social metadata are wired', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/post-share.php');
    $receipt = file_get_contents($root . '/themes/cream/templates/partials/post-receipt.php');
    $post = file_get_contents($root . '/themes/cream/templates/post.php');
    $layout = file_get_contents($root . '/themes/cream/templates/layout.php');
    $footer = file_get_contents($root . '/themes/cream/templates/partials/site-footer.php');
    $plugin = file_get_contents($root . '/plugins/posts/src/PostsPlugin.php');
    $controller = file_get_contents($root . '/plugins/posts/src/PostController.php');
    $feed = file_get_contents($root . '/plugins/posts/src/FeedController.php');
    $css = file_get_contents($root . '/public/assets/posts.css');
    $script = file_get_contents($root . '/public/assets/share.js');
    $icons = file_get_contents($root . '/public/assets/icons.svg');
    truthy($partial !== false && str_contains($partial, 'data-share-card'), 'Share strip needs a script hook');
    truthy($partial !== false && str_contains($partial, 'data-share-copy') && str_contains($partial, 'data-share-native'), 'Share strip needs copy and native buttons');
    truthy($partial !== false && str_contains($partial, 'aria-live="polite"'), 'Share feedback must be announced');
    truthy($receipt !== false && str_contains($receipt, "'/post-share.php'"), 'Receipt must include the share strip');
    truthy($post !== false && str_contains($post, 'partials/post-receipt.php'), 'Post template must include the receipt');
    truthy($layout !== false && str_contains($layout, 'application/rss+xml'), 'Layout needs an RSS discovery link');
    truthy($layout !== false && str_contains($layout, '<link rel="canonical"'), 'Article pages need a canonical URL');
    truthy($layout !== false && str_contains($layout, 'property="og:image"'), 'Sharing needs an Open Graph image');
    truthy($layout !== false && str_contains($layout, 'name="twitter:card"'), 'Sharing needs a Twitter card');
    truthy($footer !== false && str_contains($footer, '/feed.xml'), 'Footer needs an RSS link');
    truthy($plugin !== false && str_contains($plugin, "'/feed.xml'"), 'Feed endpoint must be routed');
    truthy($feed !== false && str_contains($feed, 'application/rss+xml'), 'Feed must use the RSS content type');
    truthy($controller !== false && str_contains($controller, "'share_meta'") && str_contains($controller, "'share.js'"), 'Post pages must pass share metadata and load the script');
    truthy($css !== false && str_contains($css, '.post-share') && str_contains($css, '.post-share-button'), 'Share strip needs the paper-ticket style');
    truthy($script !== false && str_contains($script, 'navigator.clipboard') && str_contains($script, "execCommand('copy')"), 'Copy must work with and without the clipboard API');
    truthy($script !== false && str_contains($script, 'navigator.share'), 'Native sharing must be feature-detected');
    truthy($icons !== false && str_contains($icons, 'i-link') && str_contains($icons, 'i-share'), 'Share controls need themed icons');
    truthy(is_file($root . '/public/assets/social-card.png'), 'Open Graph image asset is missing');
});

check('Reader receipt merges reactions and sharing into a floater', function (): void {
    $root = dirname(__DIR__);
    $partial = file_get_contents($root . '/themes/cream/templates/partials/post-receipt.php');
    $post = file_get_contents($root . '/themes/cream/templates/post.php');
    $controller = file_get_contents($root . '/plugins/posts/src/PostController.php');
    $css = file_get_contents($root . '/public/assets/receipt.css');
    $script = file_get_contents($root . '/public/assets/receipt.js');
    truthy($partial !== false && str_contains($partial, 'data-post-receipt'), 'Receipt needs a script hook');
    truthy($partial !== false && str_contains($partial, 'data-receipt-tab') && str_contains($partial, 'hidden'), 'Floating tab must start hidden for no-script fallback');
    truthy($partial !== false && str_contains($partial, 'aria-controls="post-receipt-panel"'), 'Receipt tab must control the panel');
    truthy($partial !== false && str_contains($partial, "'/reactions.php'"), 'Receipt must embed reactions');
    truthy($partial !== false && str_contains($partial, "'/post-share.php'"), 'Receipt must embed sharing');
    truthy($post !== false && !str_contains($post, 'partials/post-share.php') && str_contains($post, 'partials/post-receipt.php'), 'Post must use one unified receipt');
    truthy($controller !== false && str_contains($controller, "'receipt.css'") && str_contains($controller, "'receipt.js'"), 'Post pages must load the receipt layer');
    truthy($css !== false && str_contains($css, '.post-receipt-panel') && str_contains($css, 'grid-template-columns: minmax(0, 1fr) minmax(244px, 0.78fr)'), 'Desktop receipt must use two compartments');
    truthy($css !== false && str_contains($css, '.post-receipt[data-receipt-floating="true"]'), 'Receipt needs a floating state');
    truthy($css !== false && str_contains($css, 'top: var(--receipt-y, 62vh)'), 'Floating receipt must remember its edge position');
    truthy($css !== false && str_contains($css, 'transform: translate(0, -50%)'), 'Receipt panel must slide out from the edge');
    truthy($script !== false && str_contains($script, "matchMedia('(max-width: 700px)')"), 'Floating receipt must be narrow-screen only');
    truthy($script !== false && str_contains($script, "data-receipt-floating"), 'Script must toggle the floating state');
    truthy($script !== false && str_contains($script, 'pointermove') && str_contains($script, 'setPointerCapture'), 'Tape tab must support vertical dragging');
    truthy($script !== false && str_contains($script, 'localStorage.getItem(storageKey)'), 'Tape position must persist locally');
    truthy($script !== false && str_contains($script, "event.key !== 'Escape'"), 'Receipt must close with Escape');
    truthy($script !== false && str_contains($script, "setAttribute('inert'"), 'Closed receipt must leave the focus order');
    truthy($script !== false && str_contains($script, "classList.add('receipt-script')"), 'Script must mark enhanced layout');
    truthy($script !== false && str_contains($script, 'document.body.appendChild(receipt)'), 'Floating receipt must escape article stacking context');
    truthy($css !== false && str_contains($css, 'html.receipt-script'), 'Enhanced share layout must be scoped to script mode');
    $share = file_get_contents($root . '/public/assets/share.js');
    $icons = file_get_contents($root . '/public/assets/icons.svg');
    truthy($share !== false && str_contains($share, '复制成功') && str_contains($share, "setCopyIcon('check')"), 'Copy success must switch the button to a check label');
    truthy($share !== false && str_contains($share, "setStatus('')"), 'Copy success must clear the red helper line');
    truthy($icons !== false && str_contains($icons, 'id="i-check"'), 'The icon sprite needs a check glyph');
    truthy($css !== false && str_contains($css, '--receipt-green') && str_contains($css, 'width: min(252px'), 'Floating receipt must use a compact green success style');
});

check('Sitemap XML escapes locations and formats priorities', function (): void {
    $xml = SitemapBuilder::render([
        [
            'loc' => 'https://example.com/?a=1&b=2',
            'lastmod' => '2026-11-20',
            'changefreq' => 'daily',
            'priority' => 0.85,
        ],
    ]);
    truthy(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>'), 'Sitemap must be XML');
    truthy(str_contains($xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'), 'Sitemap must use the sitemap namespace');
    truthy(str_contains($xml, '<loc>https://example.com/?a=1&amp;b=2</loc>'), 'Sitemap must escape ampersands');
    truthy(str_contains($xml, '<lastmod>2026-11-20</lastmod>'), 'Sitemap must include lastmod');
    truthy(str_contains($xml, '<priority>0.9</priority>'), 'Sitemap must format priority to one decimal');
});

check('Website structured data exposes search action', function (): void {
    $data = StructuredData::website([
        'title' => '日记Planner',
        'description' => '把日子写进计划里。',
        'language' => 'zh-CN',
    ], 'https://example.com/');
    truthy(($data['@type'] ?? null) === 'WebSite', 'Structured data must describe a WebSite');
    truthy(($data['potentialAction']['target']['urlTemplate'] ?? null) === 'https://example.com/?search={search_term_string}', 'WebSite search action must point at the overlay entry');
});

check('Public SEO routes expose sitemap, robots and structured data', function (): void {
    $root = dirname(__DIR__);
    $seo = file_get_contents($root . '/plugins/web/src/SeoController.php');
    $web = file_get_contents($root . '/plugins/web/src/WebPlugin.php');
    $layout = file_get_contents($root . '/themes/cream/templates/layout.php');
    $home = file_get_contents($root . '/plugins/web/src/HomeController.php');
    $posts = file_get_contents($root . '/plugins/posts/src/PostListController.php');
    $post = file_get_contents($root . '/plugins/posts/src/PostController.php');
    $tags = file_get_contents($root . '/plugins/posts/src/TagController.php');
    $pages = file_get_contents($root . '/plugins/pages/src/PageController.php');
    $pageRepository = file_get_contents($root . '/core/Page/PageRepository.php');
    $postRepository = file_get_contents($root . '/core/Content/PostRepository.php');
    truthy($seo !== false && str_contains($seo, 'SitemapBuilder::render'), 'SEO controller must build the sitemap');
    truthy($seo !== false && str_contains($seo, 'Sitemap: '), 'Robots must point at the sitemap');
    truthy($seo !== false && str_contains($seo, 'Disallow: /search'), 'Robots must keep search out of crawl');
    truthy($web !== false && str_contains($web, 'SeoController') && str_contains($web, '/sitemap.xml') && str_contains($web, '/robots.txt'), 'Web plugin must register SEO routes');
    truthy($layout !== false && str_contains($layout, 'application/ld+json') && str_contains($layout, 'JSON_HEX_TAG'), 'Layout must output safe JSON-LD');
    truthy($home !== false && str_contains($home, 'canonical_url') && str_contains($home, 'StructuredData::website'), 'Home must expose canonical and WebSite data');
    truthy($posts !== false && str_contains($posts, 'StructuredData::collectionPage'), 'Post list must expose collection data');
    truthy($post !== false && str_contains($post, 'StructuredData::blogPosting'), 'Articles must expose BlogPosting data');
    truthy($tags !== false && str_contains($tags, 'StructuredData::collectionPage'), 'Tags must expose collection data');
    truthy($pages !== false && str_contains($pages, 'StructuredData::webPage'), 'Pages must expose WebPage data');
    truthy($pageRepository !== false && str_contains($pageRepository, 'public function slugs'), 'Page repository must list page slugs');
    truthy($postRepository !== false && str_contains($postRepository, 'publishedForSitemap'), 'Post repository must list sitemap posts');
    truthy(is_file($root . '/docs/SEO.md'), 'SEO documentation must exist');
    truthy($pages !== false && str_contains($pages, "'about'"), 'About page must mark its navigation entry');
    $header = file_get_contents($root . '/themes/cream/templates/partials/site-header.php');
    truthy($header !== false && str_contains($header, '$current === \'about\''), 'About navigation must be highlighted on the desktop header');
});

check('About page is a complete responsive journal spread', function (): void {
    $root = dirname(__DIR__);
    $about = file_get_contents($root . '/content/pages/about.php');
    $css = file_get_contents($root . '/public/assets/pages/about.css');
    truthy($about !== false && str_contains($about, 'about-hero') && str_contains($about, 'about-timeline'), 'About page needs a hero and build timeline');
    truthy($about !== false && str_contains($about, '这里有什么') && str_contains($about, '这里没有什么'), 'About page must set reader expectations');
    truthy($about !== false && str_contains($about, 'about-footer') && str_contains($about, 'about-colophon'), 'About page needs a colophon footer');
    truthy($css !== false && str_contains($css, '.about-now') && str_contains($css, '.about-boundary'), 'About styles must cover the now card and boundaries');
    truthy($css !== false && str_contains($css, '@media (max-width: 560px)') && str_contains($css, 'prefers-reduced-motion'), 'About page must be responsive and motion-safe');
});

check('Series feature is removed from routes, templates and schema', function (): void {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112102_drop_series.php');
    truthy($migration !== false && str_contains($migration, 'DROP FOREIGN KEY fk_posts_series'), 'Drop migration must remove the foreign key');
    truthy($migration !== false && str_contains($migration, 'DROP TABLE IF EXISTS series'), 'Drop migration must remove the series table');
    foreach (['posts', 'admin', 'api', 'web'] as $plugin) {
        $manifest = file_get_contents($root . '/plugins/' . $plugin . '/plugin.json');
        truthy($manifest !== false && !str_contains($manifest, '/series'), 'Plugin still exposes series routes: ' . $plugin);
    }
    truthy(!is_file($root . '/themes/cream/templates/series.php'), 'Series template must be gone');
    truthy(!is_file($root . '/themes/cream/templates/admin/series.php'), 'Series admin template must be gone');
});

check('Release notes render safe headings and bullets', function (): void {
    Theme::load(dirname(__DIR__), 'cream');
    $notes = cream_update_notes("### 新功能\n- **版本升级页**：后台集中展示。\n<script>alert(1)</script>");
    truthy(str_contains($notes, '<h3 class="update-notes-heading">新功能</h3>'), 'Release notes need a heading');
    truthy(str_contains($notes, '<ul class="update-notes-list">'), 'Release notes need a bullet list');
    truthy(str_contains($notes, '<strong>版本升级页</strong>'), 'Release notes need inline bold');
    truthy(!str_contains($notes, '<script>'), 'Release notes must escape HTML');
});

check('Release catalog accepts only stable tagged assets', function (): void {
    $payload = [
        'tag_name' => 'v0.1.99',
        'draft' => false,
        'prerelease' => false,
        'name' => 'v0.1.99',
        'body' => "## 更新\n说明",
        'published_at' => '2026-11-22T00:00:00Z',
        'html_url' => 'https://github.com/Lisir2002/Mini-BlogPlanner/releases/tag/v0.1.99',
        'assets' => [
            [
                'name' => 'Mini-BlogPlanner-v0.1.99.zip',
                'browser_download_url' => 'https://github.com/Lisir2002/Mini-BlogPlanner/releases/download/v0.1.99/Mini-BlogPlanner-v0.1.99.zip',
                'size' => 1024,
            ],
            [
                'name' => 'Mini-BlogPlanner-v0.1.99.zip.sha256',
                'browser_download_url' => 'https://github.com/Lisir2002/Mini-BlogPlanner/releases/download/v0.1.99/Mini-BlogPlanner-v0.1.99.zip.sha256',
                'size' => 95,
            ],
        ],
    ];
    $release = (new ReleaseCatalog())->parse($payload);
    same('0.1.99', $release['app']);
    same('v0.1.99', $release['tag']);
    same('Mini-BlogPlanner-v0.1.99.zip', $release['zip']['name']);

    $prerelease = $payload;
    $prerelease['tag_name'] = 'v0.1.99-rc1';
    throws(RuntimeException::class, static fn () => (new ReleaseCatalog())->parse($prerelease));

    $missing = $payload;
    $missing['assets'] = [$payload['assets'][0]];
    throws(RuntimeException::class, static fn () => (new ReleaseCatalog())->parse($missing));
});

check('Release archive rejects traversal and extracts a valid package', function (): void {
    $directory = sys_get_temp_dir() . '/cj-archive-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $valid = $directory . '/valid.zip';
    $zip = new ZipArchive();
    same(true, $zip->open($valid, ZipArchive::CREATE));
    $zip->addFromString('version.json', '{"app":"0.1.99"}');
    $zip->close();

    $archive = new ReleaseArchive($valid);
    $inspection = $archive->inspect();
    same(1, $inspection['files']);
    $archive->extractTo($directory . '/extract');
    truthy(is_file($directory . '/extract/version.json'), 'Valid archive must extract');

    $bad = $directory . '/bad.zip';
    $zip = new ZipArchive();
    same(true, $zip->open($bad, ZipArchive::CREATE));
    $zip->addFromString('../escape.txt', 'bad');
    $zip->close();
    throws(RuntimeException::class, static fn () => (new ReleaseArchive($bad))->inspect());
    rrmdir($directory);
});

check('Update job persists state and detects stale queues', function (): void {
    $directory = sys_get_temp_dir() . '/cj-update-job-' . bin2hex(random_bytes(4));
    $jobs = new UpdateJob($directory);
    $jobs->save([
        'status' => 'queued',
        'step' => 'queued',
        'message' => '等待更新。',
        'tag' => 'v0.1.99',
        'updated_at' => gmdate('c'),
    ]);
    $job = $jobs->load();
    truthy(is_array($job) && $job['tag'] === 'v0.1.99', 'Update job must round-trip');
    truthy($jobs->active($job), 'Fresh queued job must be active');

    $job['updated_at'] = gmdate('c', time() - 200);
    $normalized = $jobs->normalize($job);
    same('failed', $normalized['status']);
    truthy(($normalized['stale'] ?? false) === true, 'Stale queue must be marked failed');

    $running = [
        'status' => 'running',
        'step' => 'apply',
        'from' => '0.1.60',
        'to' => '0.1.61',
        'updated_at' => gmdate('c', time() - 200),
    ];
    $reconciled = $jobs->reconcile($running, '0.1.61', false);
    same('done', $reconciled['status']);
    truthy(($reconciled['reconciled'] ?? false) === true, 'Reached target must reconcile a stale running task');
    same('running', $jobs->reconcile($running, '0.1.60', false)['status']);
    same('running', $jobs->reconcile($running, '0.1.61', true)['status']);
    rrmdir($directory);
});

check('Upgrade storage is readable by the web process', function (): void {
    $directory = sys_get_temp_dir() . '/cj-upgrade-state-' . bin2hex(random_bytes(4));
    $store = new UpgradeStateStore($directory);
    $store->save(['status' => 'done', 'release' => 'v0.1.99']);
    same(0755, fileperms($directory) & 0777, 'Upgrade state directory must be traversable');
    same(0644, fileperms($directory . '/state.json') & 0777, 'Upgrade state file must be readable');
    $loaded = $store->load();
    same('v0.1.99', $loaded['release'] ?? null);
    rrmdir($directory);
});

check('Admin updates page exposes guarded one-click flow', function (): void {
    $root = dirname(__DIR__);
    $template = file_get_contents($root . '/themes/cream/templates/admin/updates.php');
    $layout = file_get_contents($root . '/themes/cream/templates/admin/layout.php');
    $plugin = file_get_contents($root . '/plugins/admin/src/AdminPlugin.php');
    $controller = file_get_contents($root . '/plugins/admin/src/UpdatesAdminController.php');
    $helper = file_get_contents($root . '/bin/update-helper.php');
    $entry = file_get_contents($root . '/deploy/cream-update');
    $script = file_get_contents($root . '/public/assets/admin-updates.js');
    $css = file_get_contents($root . '/public/assets/admin-updates.css');
    truthy($template !== false && str_contains($template, 'data-status-url="/admin/updates/status"'), 'Updates page needs a status endpoint');
    truthy($template !== false && str_contains($template, 'data-update-region="current"') && str_contains($template, 'data-update-region="progress"') && str_contains($template, 'data-update-region="details"'), 'Updates page needs independently refreshable regions');
    truthy($template !== false && str_contains($template, 'id="update-dialog"') && str_contains($template, 'action="/admin/updates/apply"'), 'Updates page needs a guarded apply dialog');
    truthy($template !== false && str_contains($template, 'name="_csrf"') && str_contains($template, 'name="tag"'), 'Apply dialog needs CSRF and target tag');
    truthy($layout !== false && str_contains($layout, '/admin/updates.html') && str_contains($layout, '版本与升级'), 'Admin navigation must expose updates');
    truthy($plugin !== false && str_contains($plugin, "'/admin/updates'") && str_contains($plugin, "'/admin/updates/apply'"), 'Admin plugin must register updates routes');
    truthy($controller !== false && str_contains($controller, 'Csrf::validate') && str_contains($controller, 'sudo -n'), 'Controller must guard CSRF and use restricted sudo');
    truthy($controller !== false && str_contains($controller, '->reconcile('), 'Controller must reconcile an upgrade that already switched versions');
    truthy($controller !== false && !str_contains($controller, 'nohup'), 'Controller must not keep the helper inside the PHP-FPM process group');
    truthy($entry !== false && str_contains($entry, 'systemd-run') && str_contains($entry, 'CREAM_UPDATE_SERVICE'), 'Update entry must detach into an independent systemd unit');
    truthy($helper !== false && str_contains($helper, 'hash_equals') && str_contains($helper, 'ReleaseArchive') && str_contains($helper, 'posix_geteuid'), 'Helper must verify checksums and require root');
    truthy($helper !== false && str_contains($helper, 'bin/upgrade.php') && str_contains($helper, '--confirm'), 'Helper must use the existing upgrade runner');
    truthy($script !== false && str_contains($script, 'fetch(statusUrl') && str_contains($script, 'refreshRegions'), 'Updates page must poll job status and refresh only changed regions');
    truthy($script !== false && !str_contains($script, 'window.location.reload'), 'Completed updates must not reload the whole page');
    truthy($template !== false && str_contains($template, 'id="update-flash"') && str_contains($template, 'role="status"'), 'Updates page needs a visible completion status');
    truthy($script !== false && str_contains($script, 'event.preventDefault()') && str_contains($script, 'fetch(form.action'), 'Update submission must stay on the current page');
    truthy($script !== false && str_contains($script, 'const ui = () =>') && str_contains($script, "closest('[data-update-open]')"), 'Replaced regions must keep live controls and fresh DOM references');
    truthy($script !== false && str_contains($script, 'visibilitychange') && str_contains($script, 'pageshow') && str_contains($script, 'showFlash'), 'Updates page must resume polling and surface completion state');
    truthy($css !== false && str_contains($css, '.update-flash') && str_contains($css, '.update-flash.is-hidden'), 'Updates page needs completion banner styles');
    truthy($template !== false && !str_contains($template, '已部署 release'), 'Updates page must not expose the raw release directory list');
    truthy($template !== false && str_contains($template, '这次更新会做什么') && str_contains($template, '升级保障') && str_contains($template, '最近一次更新'), 'Updates page needs richer flow, safety and last-update content');
    truthy($css !== false && str_contains($css, '.update-info-grid') && str_contains($css, '.update-flow') && str_contains($css, '.update-guard-grid'), 'New update information blocks need layout styles');
    truthy($css !== false && str_contains($css, '.update-hero') && str_contains($css, '@media (max-width: 460px)'), 'Updates page needs responsive paper styles');
    truthy(is_file($root . '/deploy/cream-update'), 'Restricted update entry must be shipped');
    truthy(is_file($root . '/docs/ADMIN-UPDATES.md'), 'Updates documentation must exist');
});

check('Audit presenter names, redacts and summarizes events', function (): void {
    same('admin', AuditPresenter::category('admin.login.failed'));
    same('post', AuditPresenter::category('post.published'));
    same('system', AuditPresenter::category('system.upgrade.succeeded'));
    same('other', AuditPresenter::category('custom.event'));
    same('登录失败', AuditPresenter::label('admin.login.failed'));
    truthy(AuditPresenter::isFailure('system.upgrade.failed'), 'Failed suffix should be recognised');
    truthy(!AuditPresenter::isFailure('comment.deleted'), 'Deletion is not a failed event');

    $redacted = AuditPresenter::redact([
        'password' => 'plain',
        'meta' => ['token' => 'abc', 'ok' => 1],
        'authorization' => 'Bearer secret',
    ]);
    same('[已脱敏]', $redacted['password']);
    same('[已脱敏]', $redacted['authorization']);
    truthy(is_array($redacted['meta']), 'Nested payload must stay an array');
    same('[已脱敏]', $redacted['meta']['token'] ?? null);
    same(1, $redacted['meta']['ok'] ?? null);

    $summary = AuditPresenter::summary('post.updated', ['slug' => 'hello', 'status' => 'publish', 'id' => 12]);
    truthy(str_contains($summary, 'hello') && str_contains($summary, 'publish'), 'Summary should expose object fields');

    $fields = AuditPresenter::fields(['id' => 3, 'size' => 2097152, 'password' => 'x']);
    truthy($fields !== [], 'Fields must not be empty');
    same('文件大小', $fields[1]['label']);
    same('2.0 MB', $fields[1]['value']);
    same('[已脱敏]', $fields[2]['value']);
    truthy(!str_contains(AuditPresenter::json(['token' => 'secret']), 'secret'), 'JSON view must not leak secrets');
});

check('Audit repository filters, stats and selected fields', function (): void {
    $capture = new class implements ConnectionInterface {
        public string $sql = '';
        /** @var list<string> */
        public array $params = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->sql = $sql;
            $this->params = array_values($params);

            return ['total' => 0, 'today' => 0, 'week' => 0, 'failed' => 0, 'actors' => 0, 'now' => '2026-11-22 06:00:00'];
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $this->sql = $sql;
            $this->params = array_values($params);

            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            $this->sql = $sql;
            $this->params = array_values($params);

            return 0;
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
        }
    };

    $repository = new AuditRepository($capture);
    $repository->count([
        'q' => 'login',
        'category' => 'admin',
        'result' => 'failed',
        'from' => '2026-11-01 00:00:00',
        'to' => '2026-11-30 23:59:59',
    ]);
    truthy(str_contains($capture->sql, 'CAST(payload AS CHAR) LIKE ?'), 'Keyword search must include payload');
    truthy(str_contains($capture->sql, "event LIKE 'admin.%'"), 'Category filter must narrow admin events');
    truthy(str_contains($capture->sql, "event LIKE '%.failed'"), 'Result filter must narrow failures');
    truthy(str_contains($capture->sql, 'created_at >= ?') && str_contains($capture->sql, 'created_at <= ?'), 'Date range must be applied');
    same(5, count($capture->params), 'Keyword and date filters must bind params');
    same('login', trim($capture->params[0], '%'), 'Keyword should be parameterised');

    $repository->count(['range' => 'today']);
    truthy(str_contains($capture->sql, 'CURDATE()'), 'Today preset must use the database clock');

    $repository->search(25, 0, ['category' => 'system']);
    truthy(str_contains($capture->sql, 'plan_id') && str_contains($capture->sql, 'backup_id'), 'Rows must expose plan and backup ids');
    truthy(str_contains($capture->sql, "event LIKE 'system.%'"), 'Search must reuse the same category filter');
});

check('Audit page exposes richer safe controls', function (): void {
    $root = dirname(__DIR__);
    $template = file_get_contents($root . '/themes/cream/templates/admin/audit.php');
    $controller = file_get_contents($root . '/plugins/admin/src/AuditAdminController.php');
    $plugin = file_get_contents($root . '/plugins/admin/src/AdminPlugin.php');
    $css = file_get_contents($root . '/public/assets/admin-audit.css');

    truthy($template !== false && str_contains($template, 'name="range"') && str_contains($template, 'name="from"') && str_contains($template, 'name="to"'), 'Audit filters need time range and dates');
    truthy($template !== false && str_contains($template, 'name="q"') && str_contains($template, 'name="result"'), 'Audit filters need keyword and result');
    truthy($template !== false && str_contains($template, 'audit-category-tabs') && str_contains($template, 'audit-boundary'), 'Audit page needs category tabs and explicit boundaries');
    truthy($template !== false && str_contains($template, '查看字段') && str_contains($template, '已脱敏'), 'Audit details must be structured and redacted');
    truthy($template !== false && str_contains($template, '导出 CSV'), 'Audit page needs CSV export');
    truthy($controller !== false && str_contains($controller, '/admin/audit/export.csv'), 'Export URL must come from the controller');
    truthy($template !== false && !str_contains($template, "json_encode(\$event['payload']"), 'Audit page must not print raw legacy payload');
    truthy($controller !== false && str_contains($controller, 'function export') && str_contains($controller, "admin.audit.exported"), 'Export must be audited');
    truthy($controller !== false && str_contains($controller, "implode(',', array_keys(\$state['db']))"), 'Export audit payload should store filter names, not values');
    truthy($plugin !== false && str_contains($plugin, "'/admin/audit/export.csv'"), 'Admin plugin must register the export route');
    truthy($plugin !== false && !str_contains($plugin, "'/admin/audit/delete'"), 'Audit page must not expose a delete route');
    truthy($css !== false && str_contains($css, '.audit-timeline') && str_contains($css, '@media (max-width: 520px)'), 'Audit page needs timeline and phone styles');
});

check('Site status page and API share one safe report', function () use ($validVersion): void {
    $report = (new SiteStatus(Version::fromArray($validVersion)))->report();
    truthy(isset($report['status'], $report['app'], $report['checked_at'], $report['checks']), 'Status report needs identity, time and checks');
    truthy(is_array($report['checks']) && $report['checks'] !== [], 'Status report needs at least one check');
    truthy(!str_contains((string) json_encode($report), 'password'), 'Status report must not expose credentials');

    $root = dirname(__DIR__);
    $template = file_get_contents($root . '/themes/cream/templates/status.php');
    $css = file_get_contents($root . '/public/assets/status.css');
    $controller = file_get_contents($root . '/plugins/web/src/StatusController.php');
    $api = file_get_contents($root . '/plugins/api/src/HealthController.php');
    $plugin = file_get_contents($root . '/plugins/api/src/ApiPlugin.php');
    truthy($template !== false && str_contains($template, 'status-hero') && str_contains($template, 'status-metrics'), 'Status page needs hero and metrics');
    truthy($template !== false && str_contains($template, 'status-checks') && str_contains($template, 'status-content'), 'Status page needs checks and content summary');
    truthy($css !== false && str_contains($css, '@media (max-width: 560px)'), 'Status page needs phone styles');
    truthy($controller !== false && str_contains($controller, 'no-store') && str_contains($controller, 'noindex'), 'Status page must stay uncached and unindexed');
    truthy($api !== false && str_contains($api, 'function status') && str_contains($api, 'SiteStatus'), 'Health controller must expose the rich status report');
    truthy($plugin !== false && str_contains($plugin, "'/api/v1/status'"), 'API plugin must route the rich status report');
});

check('API tokens enforce scopes and stay hashed', function (): void {
    $row = [
        'id' => 7,
        'name' => '监控',
        'token_prefix' => 'mbp_abcdef12',
        'scopes_json' => '["status:read"]',
        'created_at' => '2026-11-22 08:00:00',
        'last_used_at' => null,
        'expires_at' => null,
        'revoked_at' => null,
    ];
    $repository = new ApiTokenRepository(new FakeConnection($row));
    $raw = 'mbp_' . str_repeat('a', 64);
    $request = new Request('GET', '/api/v1/admin/status', ['authorization' => 'Bearer ' . $raw], []);
    $token = (new ApiAuthenticator($repository))->requireScope($request, 'status:read');
    truthy($token instanceof ApiToken && $token->hasScope('status:read'), 'Valid token must pass its scope');

    $missing = (new ApiAuthenticator($repository))->requireScope(
        new Request('GET', '/api/v1/admin/status', [], []),
        'status:read',
    );
    truthy($missing instanceof Response && $missing->status() === 401, 'Missing token must return 401');
    truthy(!str_contains($missing->body(), $raw), 'Auth errors must not echo the token');
    truthy($missing instanceof Response && isset($missing->headers()['WWW-Authenticate']), '401 must advertise Bearer');

    $forbidden = (new ApiAuthenticator($repository))->requireScope($request, 'content:read');
    truthy($forbidden instanceof Response && $forbidden->status() === 403, 'Missing scope must return 403');
    truthy($forbidden instanceof Response && !isset($forbidden->headers()['WWW-Authenticate']), '403 must not trigger a Bearer challenge');

    $expired = new ApiToken(
        8,
        '过期',
        'mbp_abcdef34',
        ['status:read'],
        '2026-11-01 00:00:00',
        null,
        '2026-11-20 00:00:00',
        null,
    );
    truthy(!$expired->isActive(), 'Expired token must be inactive');

    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/2026112106_create_api_tokens.php');
    $admin = file_get_contents($root . '/plugins/admin/src/ApiTokenAdminController.php');
    $template = file_get_contents($root . '/themes/cream/templates/admin/tokens.php');
    $plugin = file_get_contents($root . '/plugins/admin/src/AdminPlugin.php');
    $apiPlugin = file_get_contents($root . '/plugins/api/src/ApiPlugin.php');
    truthy($migration !== false && str_contains($migration, 'api_tokens') && str_contains($migration, 'token_hash'), 'Token migration must store hashes');
    $auditAt = strpos($admin, "append('api.token.created'");
    truthy($auditAt !== false, 'Create action must append an audit event');
    $auditEnd = strpos($admin, ']);', (int) $auditAt);
    truthy($auditEnd !== false, 'Create audit payload must be closed');
    $auditBlock = substr($admin, (int) $auditAt, (int) $auditEnd - (int) $auditAt);
    truthy(!str_contains($auditBlock, "'token'"), 'Create audit must not store the raw token');
    truthy($template !== false && str_contains($template, 'data-copy-token') && str_contains($template, '创建令牌'), 'Token page needs one-time secret and create form');
    truthy($plugin !== false && str_contains($plugin, "'/admin/tokens'") && str_contains($plugin, "'/admin/tokens/{id}/revoke'"), 'Admin plugin must route token management');
    truthy($apiPlugin !== false && str_contains($apiPlugin, "'/api/v1/admin/status'") && str_contains($apiPlugin, "'/api/v1/admin/posts'") && str_contains($apiPlugin, "'/api/v1/admin/comments'"), 'API plugin must route protected management endpoints');
    same('创建 API 令牌', AuditPresenter::label('api.token.created'));
    same('admin', AuditPresenter::category('api.token.created'));
});

check('File rate limiter enforces a fixed window without storing identities', function (): void {
    $directory = sys_get_temp_dir() . '/cream-rate-' . bin2hex(random_bytes(4));
    $now = 1000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $limiter = new FileRateLimiter($directory, $clock);
    $identity = '203.0.113.77';

    $first = $limiter->hit('comment', $identity, 2, 60);
    truthy($first->allowed && $first->remaining === 1, 'First request must be allowed');
    $second = $limiter->hit('comment', $identity, 2, 60);
    truthy($second->allowed && $second->remaining === 0, 'Second request must consume the bucket');
    $third = $limiter->hit('comment', $identity, 2, 60);
    truthy(!$third->allowed && $third->retryAfter === 60, 'Third request must be limited');
    $third = $limiter->hit('comment', $identity, 2, 60);
    truthy(!$third->allowed && $third->retryAfter === 60, 'Denied requests must not reset the window');

    $files = glob($directory . '/*.json') ?: [];
    same(1, count($files), 'One identity must use one bucket file');
    truthy(!str_contains((string) ($files[0] ?? ''), $identity), 'Bucket file names must not expose identities');

    $now = 1061;
    $next = $limiter->hit('comment', $identity, 2, 60);
    truthy($next->allowed, 'A new window must restore the bucket');
    rrmdir($directory);
});

check('Request guard applies token, route and global quotas', function (): void {
    $limiter = new RecordingRateLimiter();
    $guard = new RequestGuard($limiter, 'test-secret');

    $api = new Request('GET', '/api/v1/admin/status', ['authorization' => 'Bearer mbp_abcdef'], []);
    truthy($guard->check($api, '203.0.113.8') === null, 'Allowed request must pass');
    same(['api-token', 'api-ip', 'general'], array_column($limiter->hits, 'scope'));
    same(600, $limiter->hits[1]['limit'], 'Admin API IP ceiling must match the documented fallback');

    $limiter->hits = [];
    $login = new Request('POST', '/admin/login.html', [], []);
    truthy($guard->check($login, '203.0.113.9') === null, 'Login request must pass');
    same(['admin-login-post', 'general'], array_column($limiter->hits, 'scope'));
    same(60, $limiter->hits[0]['limit'], 'Login must allow a realistic retry volume');

    $limiter->hits = [];
    $comment = new Request('POST', '/posts/hello/comments', [], []);
    truthy($guard->check($comment, '203.0.113.10') === null, 'Comment request must pass');
    same(['comment', 'comment-ip', 'general'], array_column($limiter->hits, 'scope'));
    same(40, $limiter->hits[1]['limit'], 'Comment IP ceiling must stay generous');

    $limiter->hits = [];
    $visitorComment = new Request('POST', '/posts/hello/reaction', [], [], ['cream_session' => str_repeat('a', 26)]);
    truthy($guard->check($visitorComment, '203.0.113.10') === null, 'Visitor reaction must pass');
    same(['reaction', 'reaction-ip', 'general'], array_column($limiter->hits, 'scope'));
    truthy($limiter->hits[0]['identity'] !== $limiter->hits[1]['identity'], 'Visitor quota must not be keyed by IP');

    $limiter->hits = [];
    $page = new Request('GET', '/posts/hello', [], [], ['cream_session' => str_repeat('b', 26)]);
    truthy($guard->check($page, '203.0.113.12') === null, 'Page request must pass');
    same(['page', 'general'], array_column($limiter->hits, 'scope'));
    truthy($limiter->hits[0]['identity'] !== $limiter->hits[1]['identity'], 'Page quota must use the visitor session');

    $limiter->hits = [];
    $publicApi = new Request('GET', '/api/v1/posts', [], []);
    truthy($guard->check($publicApi, '203.0.113.13') === null, 'Public API request must pass');
    same(['api-ip', 'general'], array_column($limiter->hits, 'scope'));
    same(300, $limiter->hits[0]['limit'], 'Public API IP ceiling must stay generous');

    $limiter->hits = [];
    $limiter->denied = RateLimitResult::deny('comment', 10, 30);
    $limited = $guard->check($comment, '203.0.113.11');
    truthy($limited !== null && !$limited->allowed && $limited->scope === 'comment', 'Guard must stop on the first denied scope');
});

check('Shared IP does not collapse distinct visitors into one quota', function (): void {
    $directory = sys_get_temp_dir() . '/cream-visitors-' . bin2hex(random_bytes(4));
    $limiter = new FileRateLimiter($directory);
    $guard = new RequestGuard($limiter, 'test-secret');
    $ip = '203.0.113.50';

    for ($visitor = 1; $visitor <= 20; $visitor++) {
        $session = str_pad(dechex($visitor), 26, '0', STR_PAD_LEFT);
        for ($page = 1; $page <= 10; $page++) {
            $request = new Request('GET', '/posts/hello', [], [], ['cream_session' => $session]);
            truthy($guard->check($request, $ip) === null, 'Normal shared-network browsing must not be limited');
        }
    }
    same(21, count(glob($directory . '/*.json') ?: []), 'Visitors and IP ceilings must use separate buckets');
    rrmdir($directory);
});

check('Login throttle allows shared-network retries before blocking', function (): void {
    $file = sys_get_temp_dir() . '/cream-login-' . bin2hex(random_bytes(4)) . '.json';
    $throttle = new LoginThrottle($file, 20, 900);
    for ($attempt = 1; $attempt <= 19; $attempt++) {
        truthy($throttle->allow('203.0.113.60'), 'Shared IP must get a realistic retry allowance');
        $throttle->hit('203.0.113.60');
    }
    truthy($throttle->allow('203.0.113.60'), 'Twentieth failure is still visible before blocking');
    $throttle->hit('203.0.113.60');
    truthy(!$throttle->allow('203.0.113.60'), 'Excess failures must still be blocked');
    @unlink($file);
});

check('Rate limited responses expose retry headers for API and pages', function (): void {
    $result = RateLimitResult::deny('api-ip', 120, 30);
    $api = RateLimitResponder::toResponse(new Request('GET', '/api/v1/posts', [], []), $result);
    same(429, $api->status());
    same('30', $api->headers()['Retry-After'] ?? null);
    same('api-ip', $api->headers()['X-RateLimit-Scope'] ?? null);
    truthy(str_contains($api->body(), '/problems/rate-limited'), 'API denial must use problem+json');

    $page = RateLimitResponder::toResponse(new Request('GET', '/posts/hello', [], []), $result);
    same(429, $page->status());
    truthy(str_contains($page->body(), '请求过于频繁') && str_contains($page->body(), '30 秒'), 'Page denial must render a retry hint');
    same('no-store', $page->headers()['Cache-Control'] ?? null);
});

check('Nginx baseline carries Cloudflare real IP and bounded request zones', function (): void {
    $root = dirname(__DIR__);
    $nginx = file_get_contents($root . '/deploy/nginx.conf.example');
    $limits = file_get_contents($root . '/deploy/nginx-rate-limit.conf.example');
    $realIp = file_get_contents($root . '/deploy/nginx-cloudflare-realip.conf.example');
    truthy($nginx !== false && str_contains($nginx, 'limit_conn cream_conn 60'), 'Nginx needs a per-IP connection cap');
    truthy($nginx !== false && substr_count($nginx, 'fastcgi_read_timeout 20s') >= 2, 'Both PHP entry points need upstream timeouts');
    truthy($nginx !== false && str_contains($nginx, 'add_header X-Frame-Options DENY always') && str_contains($nginx, 'fastcgi_hide_header X-Frame-Options'), 'Gateway headers must replace application duplicates');
    truthy($nginx !== false && str_contains($nginx, "limit_conn cream_conn 60;\n    return 301 https://\$host\$request_uri;"), 'HTTP redirect must stay rate limited');
    truthy($limits !== false && str_contains($limits, 'zone=cream_api') && str_contains($limits, 'zone=cream_auth') && str_contains($limits, 'zone=cream_write') && str_contains($limits, 'zone=cream_media'), 'Nginx needs separate rate zones');
    truthy($limits !== false && str_contains($limits, 'rate=30r/s') && str_contains($limits, 'rate=50r/s'), 'Nginx thresholds must leave room for normal bursts');
    truthy($realIp !== false && str_contains($realIp, 'real_ip_header CF-Connecting-IP') && str_contains($realIp, '173.245.48.0/20'), 'Nginx must trust only Cloudflare ranges');
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
