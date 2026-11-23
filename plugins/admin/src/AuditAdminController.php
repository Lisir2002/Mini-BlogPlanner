<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Admin\AdminNavigation;
use Core\Audit\AuditPresenter;
use Core\Audit\AuditRepository;
use Core\Config\Env;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\Theme;
use Throwable;

/**
 * @phpstan-type AuditFilterState array{
 *   q:string,event:string,actor:string,category:string,result:string,range:string,
 *   from:string,to:string,per_page:int,page:int,error:string,
 *   filter:array<string, mixed>,db:array<string, string>
 * }
 */
final class AuditAdminController
{
    use AdminErrors;

    private const PAGE_SIZES = [10];
    private const EXPORT_LIMIT = 5000;
    private const RETENTION_DAYS = 90;

    /** @var array<string, string> */
    private const RANGES = [
        'all' => '全部时间',
        'today' => '今天',
        '24h' => '最近 24 小时',
        '7d' => '最近 7 天',
        '30d' => '最近 30 天',
        '90d' => '最近 90 天',
        'custom' => '自定义日期',
    ];

    /** @var array<string, string> */
    private const CATEGORIES = [
        'all' => '全部',
        'admin' => '后台与账户',
        'post' => '内容',
        'comment' => '互动',
        'media' => '媒体',
        'tag' => '标签',
        'system' => '系统',
        'other' => '其他',
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

        $state = $this->readFilters($request->query());

        try {
            $repository = new AuditRepository(Connection::fromEnv());
            $total = $repository->count($state['db']);
            $pages = max(1, (int) ceil($total / $state['per_page']));
            $page = min($state['page'], $pages);
            $rows = $repository->search(
                $state['per_page'],
                ($page - 1) * $state['per_page'],
                $state['db'],
            );
            $stats = $repository->stats();
            $categoryCounts = $repository->categoryCounts();
            $eventNames = $repository->eventNames();
            $actors = $repository->actors();
            $now = $repository->now();
            $olderCount = $repository->countOlderThanDays(self::RETENTION_DAYS);
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->presentEvent($row, $now);
        }

        $categoryTabs = [];
        foreach (self::CATEGORIES as $key => $label) {
            $categoryTabs[] = [
                'key' => $key,
                'label' => $label,
                'total' => $key === 'all' ? $stats['total'] : ($categoryCounts[$key] ?? 0),
                'active' => $state['category'] === $key,
                'url' => $this->url('/admin/audit.html', $state['filter'], ['category' => $key === 'all' ? '' : $key, 'page' => '']),
            ];
        }

        $activeFilters = $this->activeFilters($state);
        $eventOptions = [];
        foreach ($eventNames as $item) {
            $eventOptions[] = [
                'name' => $item['name'],
                'label' => AuditPresenter::label($item['name']),
                'total' => $item['total'],
            ];
        }

        $topEvent = isset($eventNames[0]) ? [
            'name' => $eventNames[0]['name'],
            'label' => AuditPresenter::label($eventNames[0]['name']),
            'total' => $eventNames[0]['total'],
        ] : null;

        $html = $this->theme->render('admin/audit', [
            'site' => [
                'title' => (string) Env::get('APP_NAME', '日记Planner'),
                'description' => (string) Env::get('APP_DESCRIPTION', ''),
                'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            ],
            'title' => AdminNavigation::label('audit'),
            'current' => 'audit',
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'events' => $events,
            'eventNames' => $eventNames,
            'eventOptions' => $eventOptions,
            'actors' => $actors,
            'filter' => $state['filter'],
            'q' => $state['q'],
            'eventFilter' => $state['event'],
            'actorFilter' => $state['actor'],
            'category' => $state['category'],
            'result' => $state['result'],
            'range' => $state['range'],
            'from' => $state['from'],
            'to' => $state['to'],
            'dateError' => $state['error'],
            'perPage' => $state['per_page'],
            'pageSizes' => self::PAGE_SIZES,
            'ranges' => self::RANGES,
            'categoryTabs' => $categoryTabs,
            'activeFilters' => $activeFilters,
            'hasFilter' => $activeFilters !== [],
            'stats' => $stats,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageLinks' => $this->pageLinks($page, $pages, $state['filter']),
            'prevUrl' => $page > 1 ? $this->pageUrl($state['filter'], $page - 1) : null,
            'nextUrl' => $page < $pages ? $this->pageUrl($state['filter'], $page + 1) : null,
            'clearUrl' => '/admin/audit.html',
            'exportUrl' => $this->url('/admin/audit/export.csv', $state['filter']),
            'exportLimit' => self::EXPORT_LIMIT,
            'exportCount' => min($total, self::EXPORT_LIMIT),
            'olderCount' => $olderCount,
            'retentionDays' => self::RETENTION_DAYS,
            'topEvent' => $topEvent,
            'page_styles' => ['admin-audit.css'],
        ], 'admin/layout');

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function export(Request $request): Response
    {
        $admin = $this->guard->authenticate($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $state = $this->readFilters($request->query());

        try {
            $repository = new AuditRepository(Connection::fromEnv());
            $rows = $repository->search(self::EXPORT_LIMIT, 0, $state['db']);
            $now = $repository->now();
        } catch (Throwable) {
            return $this->adminError($request, 503, '后台暂时无法连接数据库。');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $this->adminError($request, 500, '导出文件创建失败，请稍后再试。');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv(
            $stream,
            ['时间', '分类', '事件', '说明', '结果', '操作者', '升级计划', '备份编号', '摘要', '字段'],
            ',',
            '"',
            '',
        );

        foreach ($rows as $row) {
            $entry = $this->presentEvent($row, $now);
            fputcsv(
                $stream,
                [
                    $this->csvSafe($entry['created_at']),
                    $this->csvSafe($entry['category_label']),
                    $this->csvSafe($entry['event']),
                    $this->csvSafe($entry['label']),
                    $entry['failed'] ? '失败' : '成功',
                    $this->csvSafe($entry['actor']),
                    $this->csvSafe($entry['plan_id']),
                    $this->csvSafe($entry['backup_id']),
                    $this->csvSafe($entry['summary']),
                    $this->csvSafe($entry['payload_json']),
                ],
                ',',
                '"',
                '',
            );
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($csv)) {
            return $this->adminError($request, 500, '导出文件读取失败，请稍后再试。');
        }

        try {
            $repository->append('admin.audit.exported', $admin->username, [
                'rows' => count($rows),
                'limit' => self::EXPORT_LIMIT,
                'filters' => implode(',', array_keys($state['db'])),
            ]);
        } catch (Throwable) {
            // 导出已经完成，审计写入失败不能反过来阻断文件下载。
        }

        $filename = 'audit-' . gmdate('Ymd-Hi') . '.csv';

        return Response::binary($csv, 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @param array{id:int,event:string,actor:string,plan_id:string,backup_id:string,created_at:string,payload:array<string, mixed>} $row
     * @return array{
     *   id:int,event:string,label:string,category:string,category_label:string,actor:string,
     *   plan_id:string,backup_id:string,created_at:string,datetime:string,absolute_time:string,
     *   relative_time:string,failed:bool,summary:string,
     *   fields:list<array{key:string,label:string,value:string}>,payload_json:string
     * }
     */
    private function presentEvent(array $row, string $dbNow): array
    {
        $payload = AuditPresenter::redact($row['payload']);
        $category = AuditPresenter::category($row['event']);
        $failed = AuditPresenter::isFailure($row['event']);
        [$absolute, $relative] = $this->describeTime($row['created_at'], $dbNow);

        return [
            'id' => $row['id'],
            'event' => $row['event'],
            'label' => AuditPresenter::label($row['event']),
            'category' => $category,
            'category_label' => AuditPresenter::categoryLabel($category),
            'actor' => $row['actor'],
            'plan_id' => $row['plan_id'],
            'backup_id' => $row['backup_id'],
            'created_at' => $row['created_at'],
            'datetime' => str_replace(' ', 'T', $row['created_at']),
            'absolute_time' => $absolute,
            'relative_time' => $relative,
            'failed' => $failed,
            'summary' => AuditPresenter::summary($row['event'], $payload),
            'fields' => AuditPresenter::fields($payload),
            'payload_json' => AuditPresenter::json($payload),
        ];
    }

    /** @return array{0:string,1:string} */
    private function describeTime(string $createdAt, string $dbNow): array
    {
        $created = strtotime($createdAt . ' UTC');
        $now = strtotime($dbNow . ' UTC');
        if ($created === false) {
            return [$createdAt, ''];
        }

        $absolute = gmdate('Y.m.d H:i', $created);
        if ($now === false) {
            return [$absolute, ''];
        }

        $diff = max(0, $now - $created);
        if ($diff < 60) {
            $relative = '刚刚';
        } elseif ($diff < 3600) {
            $relative = (int) floor($diff / 60) . ' 分钟前';
        } elseif ($diff < 86400) {
            $relative = (int) floor($diff / 3600) . ' 小时前';
        } elseif ($diff < 604800) {
            $relative = (int) floor($diff / 86400) . ' 天前';
        } else {
            $relative = $absolute;
        }

        return [$absolute, $relative];
    }

    /**
     * @param array<string, mixed> $query
     * @return AuditFilterState
     */
    private function readFilters(array $query): array
    {
        $q = mb_substr($this->queryValue($query, 'q'), 0, 80);
        $event = mb_substr($this->queryValue($query, 'event'), 0, 80);
        $actor = mb_substr($this->queryValue($query, 'actor'), 0, 120);
        $category = $this->queryValue($query, 'category');
        if (!array_key_exists($category, self::CATEGORIES)) {
            $category = 'all';
        }
        $result = $this->queryValue($query, 'result');
        if (!in_array($result, ['', 'success', 'failed'], true)) {
            $result = '';
        }
        $range = $this->queryValue($query, 'range');
        if (!array_key_exists($range, self::RANGES)) {
            $range = 'all';
        }

        [$from, $to, $error] = $this->readDateRange(
            $this->queryValue($query, 'from'),
            $this->queryValue($query, 'to'),
        );
        if ($from !== '' || $to !== '') {
            $range = 'custom';
        } elseif ($range === 'custom') {
            $range = 'all';
        }

        $perPage = (int) $this->queryValue($query, 'per_page');
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = 10;
        }
        $page = max(1, (int) $this->queryValue($query, 'page'));

        $filter = [];
        foreach ([
            'q' => $q,
            'event' => $event,
            'actor' => $actor,
            'category' => $category === 'all' ? '' : $category,
            'result' => $result,
            'range' => $range === 'all' ? '' : $range,
            'from' => $from,
            'to' => $to,
        ] as $key => $value) {
            if ($value !== '') {
                $filter[$key] = $value;
            }
        }

        $db = [];
        foreach (['q', 'event', 'actor', 'category', 'result'] as $key) {
            if (isset($filter[$key])) {
                $db[$key] = (string) $filter[$key];
            }
        }
        if ($range !== 'all' && $range !== 'custom') {
            $db['range'] = $range;
        }
        if ($from !== '') {
            $db['from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $db['to'] = $to . ' 23:59:59';
        }

        return [
            'q' => $q,
            'event' => $event,
            'actor' => $actor,
            'category' => $category,
            'result' => $result,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'per_page' => $perPage,
            'page' => $page,
            'error' => $error,
            'filter' => $filter,
            'db' => $db,
        ];
    }

    /** @return array{0:string,1:string,2:string} */
    private function readDateRange(string $from, string $to): array
    {
        $rawFrom = trim($from);
        $rawTo = trim($to);
        $from = $this->validDate($rawFrom);
        $to = $this->validDate($rawTo);
        if (($rawFrom !== '' && $from === '') || ($rawTo !== '' && $to === '')) {
            return ['', '', '日期格式应为 YYYY-MM-DD，已忽略无效日期。'];
        }
        if ($from === '' && $to === '') {
            return ['', '', ''];
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            return ['', '', '开始日期晚于结束日期，已忽略日期区间。'];
        }

        return [$from, $to, ''];
    }

    private function validDate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * @param AuditFilterState $state
     * @return list<array{kind:string,label:string,value:string,url:string}>
     */
    private function activeFilters(array $state): array
    {
        $active = [];
        $remove = fn (array $override): string => $this->url('/admin/audit.html', $state['filter'], $override);

        if ($state['q'] !== '') {
            $active[] = ['kind' => 'q', 'label' => '关键词', 'value' => $state['q'], 'url' => $remove(['q' => ''])];
        }
        if ($state['event'] !== '') {
            $active[] = [
                'kind' => 'event',
                'label' => '事件',
                'value' => AuditPresenter::label($state['event']) . ' · ' . $state['event'],
                'url' => $remove(['event' => '']),
            ];
        }
        if ($state['actor'] !== '') {
            $active[] = ['kind' => 'actor', 'label' => '操作者', 'value' => $state['actor'], 'url' => $remove(['actor' => ''])];
        }
        if ($state['category'] !== 'all') {
            $active[] = [
                'kind' => 'category',
                'label' => '分类',
                'value' => self::CATEGORIES[$state['category']] ?? $state['category'],
                'url' => $remove(['category' => '']),
            ];
        }
        if ($state['result'] !== '') {
            $active[] = [
                'kind' => 'result',
                'label' => '结果',
                'value' => $state['result'] === 'failed' ? '失败' : '成功',
                'url' => $remove(['result' => '']),
            ];
        }
        if ($state['range'] !== 'all' && $state['range'] !== 'custom') {
            $active[] = [
                'kind' => 'range',
                'label' => '时间',
                'value' => self::RANGES[$state['range']] ?? $state['range'],
                'url' => $remove(['range' => '']),
            ];
        }
        if ($state['range'] === 'custom') {
            $value = ($state['from'] !== '' ? $state['from'] : '最早')
                . ' 至 '
                . ($state['to'] !== '' ? $state['to'] : '现在');
            $active[] = [
                'kind' => 'date',
                'label' => '日期',
                'value' => $value,
                'url' => $remove(['range' => '', 'from' => '', 'to' => '']),
            ];
        }

        return $active;
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<array{page:?int,url:?string,current:bool,gap:bool}>
     */
    private function pageLinks(int $page, int $pages, array $filter): array
    {
        $entries = [];
        $add = static function (int $target) use (&$entries, $page, $filter): void {
            $entries[] = [
                'page' => $target,
                'url' => '/admin/audit.html?' . http_build_query($filter + ['page' => $target]),
                'current' => $target === $page,
                'gap' => false,
            ];
        };
        $gap = static function () use (&$entries): void {
            $entries[] = ['page' => null, 'url' => null, 'current' => false, 'gap' => true];
        };

        if ($pages <= 7) {
            for ($target = 1; $target <= $pages; $target++) {
                $add($target);
            }

            return $entries;
        }

        $add(1);
        if ($page > 4) {
            $gap();
        }
        $start = max(2, $page - 2);
        $end = min($pages - 1, $page + 2);
        for ($target = $start; $target <= $end; $target++) {
            $add($target);
        }
        if ($page < $pages - 3) {
            $gap();
        }
        $add($pages);

        return $entries;
    }

    /** @param array<string, mixed> $filter */
    private function pageUrl(array $filter, int $page): string
    {
        return '/admin/audit.html?' . http_build_query($filter + ['page' => $page]);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $override
     */
    private function url(string $path, array $filter, array $override = []): string
    {
        $params = $filter;
        foreach ($override as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    /** @param array<string, mixed> $query */
    private function queryValue(array $query, string $key): string
    {
        $value = $query[$key] ?? '';
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function csvSafe(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/^[\s]*[=+\-@\t]/u', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}
