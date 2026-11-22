<?php
$events = is_array($events ?? null) ? $events : [];
$eventOptions = is_array($eventOptions ?? null) ? $eventOptions : [];
$actors = is_array($actors ?? null) ? $actors : [];
$categoryTabs = is_array($categoryTabs ?? null) ? $categoryTabs : [];
$activeFilters = is_array($activeFilters ?? null) ? $activeFilters : [];
$pageLinks = is_array($pageLinks ?? null) ? $pageLinks : [];
$pageSizes = is_array($pageSizes ?? null) ? $pageSizes : [10];
$ranges = is_array($ranges ?? null) ? $ranges : [];
$stats = is_array($stats ?? null) ? $stats : ['total' => 0, 'today' => 0, 'week' => 0, 'failed' => 0, 'actors' => 0];
$topEvent = is_array($topEvent ?? null) ? $topEvent : null;
$q = (string) ($q ?? '');
$eventFilter = (string) ($eventFilter ?? '');
$actorFilter = (string) ($actorFilter ?? '');
$category = (string) ($category ?? 'all');
$result = (string) ($result ?? '');
$range = (string) ($range ?? 'all');
$from = (string) ($from ?? '');
$to = (string) ($to ?? '');
$dateError = (string) ($dateError ?? '');
$perPage = (int) ($perPage ?? 10);
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$total = (int) ($total ?? 0);
$prevUrl = is_string($prevUrl ?? null) ? $prevUrl : null;
$nextUrl = is_string($nextUrl ?? null) ? $nextUrl : null;
$clearUrl = (string) ($clearUrl ?? '/admin/audit.html');
$exportUrl = (string) ($exportUrl ?? '/admin/audit/export.csv');
$exportLimit = (int) ($exportLimit ?? 5000);
$exportCount = (int) ($exportCount ?? 0);
$olderCount = (int) ($olderCount ?? 0);
$retentionDays = (int) ($retentionDays ?? 90);
$hasFilter = (bool) ($hasFilter ?? false);
$eventKnown = false;
foreach ($eventOptions as $option) {
    if ((string) ($option['name'] ?? '') === $eventFilter) {
        $eventKnown = true;
        break;
    }
}
?>
<section class="admin-page audit-page">
  <div class="audit-hero">
    <div class="audit-hero-copy">
      <span class="audit-eyebrow">BACKSTAGE · AUDIT TRAIL</span>
      <h2 class="audit-title">审计日志</h2>
      <p class="audit-lede">谁在什么时候改了什么，一次写清。这里只读、可筛选、可导出，不提供后台删除。</p>
    </div>
    <div class="audit-hero-actions">
      <span class="audit-readonly"><?= cream_icon('shield', 'icon-xs') ?> 只读记录</span>
      <a class="admin-button primary" href="<?= cream_e($exportUrl) ?>">
        <?= cream_icon('arrow-right', 'icon-xs') ?> 导出 CSV
      </a>
    </div>
  </div>

  <div class="audit-stats" aria-label="审计概览">
    <a class="audit-stat" href="<?= cream_e($clearUrl) ?>">
      <span class="audit-stat-label">全部记录</span>
      <strong><?= $stats['total'] ?? 0 ?></strong>
      <small>数据库内全部事件</small>
    </a>
    <a class="audit-stat" href="/admin/audit.html?range=today">
      <span class="audit-stat-label">今天</span>
      <strong><?= $stats['today'] ?? 0 ?></strong>
      <small>按服务器日界统计</small>
    </a>
    <a class="audit-stat" href="/admin/audit.html?range=7d">
      <span class="audit-stat-label">最近 7 天</span>
      <strong><?= $stats['week'] ?? 0 ?></strong>
      <small>含今天在内的滚动区间</small>
    </a>
    <a class="audit-stat<?= (int) ($stats['failed'] ?? 0) > 0 ? ' is-alert' : '' ?>" href="/admin/audit.html?result=failed">
      <span class="audit-stat-label">失败事件</span>
      <strong><?= $stats['failed'] ?? 0 ?></strong>
      <small>登录失败、升级失败等</small>
    </a>
    <article class="audit-stat is-quiet">
      <span class="audit-stat-label">操作者</span>
      <strong><?= $stats['actors'] ?? 0 ?></strong>
      <small>不同用户名或来源</small>
    </article>
  </div>

  <div class="audit-stats-foot">
    <?php if ($topEvent !== null): ?>
    <span>出现最多：<strong><?= cream_e((string) ($topEvent['label'] ?? '')) ?></strong> <code><?= cream_e((string) ($topEvent['name'] ?? '')) ?></code> · <?= (int) ($topEvent['total'] ?? 0) ?> 次</span>
    <?php else: ?>
    <span>还没有审计记录。</span>
    <?php endif; ?>
    <span>时间按服务器时钟显示，相对时间由服务器时间换算。</span>
  </div>

  <nav class="audit-category-tabs" aria-label="按事件分类筛选">
    <?php foreach ($categoryTabs as $tab): ?>
    <a class="audit-category-tab<?= !empty($tab['active']) ? ' is-current' : '' ?>" href="<?= cream_e((string) ($tab['url'] ?? $clearUrl)) ?>"<?= !empty($tab['active']) ? ' aria-current="page"' : '' ?>>
      <span><?= cream_e((string) ($tab['label'] ?? '')) ?></span>
      <strong><?= (int) ($tab['total'] ?? 0) ?></strong>
    </a>
    <?php endforeach; ?>
  </nav>

  <form class="audit-filter-panel" method="get" action="/admin/audit.html" id="audit-filter" role="search" data-admin-filter-panel data-admin-filter-label="筛选日志" data-active-filter-count="<?= count($activeFilters) ?>">
    <input type="hidden" name="category" value="<?= cream_e($category === 'all' ? '' : $category) ?>">
    <div class="audit-filter-grid">
      <label class="audit-field audit-field-search">
        <span>关键词</span>
        <div class="audit-input-with-icon">
          <?= cream_icon('search', 'icon-xs') ?>
          <input type="search" name="q" value="<?= cream_e($q) ?>" maxlength="80" autocomplete="off" placeholder="事件、操作者或字段内容">
        </div>
      </label>
      <label class="audit-field audit-field-actor">
        <span>操作者</span>
        <input type="text" name="actor" value="<?= cream_e($actorFilter) ?>" maxlength="120" list="audit-actor-list" autocomplete="off" placeholder="精确用户名">
        <datalist id="audit-actor-list">
          <?php foreach ($actors as $actor): ?>
          <option value="<?= cream_e((string) ($actor['name'] ?? '')) ?>" label="<?= (int) ($actor['total'] ?? 0) ?> 条"></option>
          <?php endforeach; ?>
        </datalist>
      </label>
      <label class="audit-field audit-field-event">
        <span>事件</span>
        <select name="event">
          <option value="">全部事件</option>
          <?php if ($eventFilter !== '' && !$eventKnown): ?>
          <option value="<?= cream_e($eventFilter) ?>" selected><?= cream_e($eventFilter) ?></option>
          <?php endif; ?>
          <?php foreach ($eventOptions as $option): ?>
          <option value="<?= cream_e((string) ($option['name'] ?? '')) ?>"<?= $eventFilter === (string) ($option['name'] ?? '') ? ' selected' : '' ?>>
            <?= cream_e((string) ($option['label'] ?? '')) ?> · <?= cream_e((string) ($option['name'] ?? '')) ?>（<?= (int) ($option['total'] ?? 0) ?>）
          </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="audit-field">
        <span>结果</span>
        <select name="result">
          <option value=""<?= $result === '' ? ' selected' : '' ?>>成功与失败</option>
          <option value="success"<?= $result === 'success' ? ' selected' : '' ?>>只看成功</option>
          <option value="failed"<?= $result === 'failed' ? ' selected' : '' ?>>只看失败</option>
        </select>
      </label>
      <label class="audit-field">
        <span>时间</span>
        <select name="range">
          <?php foreach ($ranges as $key => $label): ?>
          <option value="<?= cream_e((string) $key) ?>"<?= $range === (string) $key ? ' selected' : '' ?>><?= cream_e((string) $label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="audit-field">
        <span>开始日期</span>
        <input type="date" name="from" value="<?= cream_e($from) ?>">
      </label>
      <label class="audit-field">
        <span>结束日期</span>
        <input type="date" name="to" value="<?= cream_e($to) ?>">
      </label>
    </div>

    <?php if ($dateError !== ''): ?>
    <p class="audit-filter-error" role="alert"><?= cream_icon('alert', 'icon-xs') ?> <?= cream_e($dateError) ?></p>
    <?php endif; ?>

    <div class="audit-filter-actions">
      <button class="admin-button primary" type="submit">应用筛选</button>
      <a class="admin-button ghost" href="<?= cream_e($clearUrl) ?>">清空条件</a>
      <label class="audit-field audit-field-size">
        <span>每页</span>
        <span class="audit-fixed-page">固定 10 条</span>
      </label>
      <span class="audit-filter-note">事件和操作者为精确匹配；关键词会搜索事件名、操作者和字段内容。</span>
    </div>
  </form>

  <?php if ($activeFilters !== []): ?>
  <div class="audit-active-bar">
    <span class="audit-active-label">当前筛选</span>
    <?php foreach ($activeFilters as $item): ?>
    <a class="audit-chip" href="<?= cream_e((string) ($item['url'] ?? $clearUrl)) ?>" title="移除这个条件">
      <span><?= cream_e((string) ($item['label'] ?? '')) ?></span>
      <strong><?= cream_e((string) ($item['value'] ?? '')) ?></strong>
      <i aria-hidden="true">×</i>
    </a>
    <?php endforeach; ?>
    <a class="audit-active-clear" href="<?= cream_e($clearUrl) ?>">全部清除</a>
  </div>
  <?php endif; ?>

  <div class="audit-result-head">
    <div class="audit-result-count">
      <strong><?= $total ?> 条记录</strong>
      <span>第 <?= $page ?> / <?= $pages ?> 页</span>
      <?php if ($hasFilter): ?>
      <span>已按当前条件筛选</span>
      <?php endif; ?>
    </div>
    <div class="audit-result-side">
      <span>按时间倒序</span>
      <span>导出上限 <?= $exportLimit ?> 条</span>
    </div>
  </div>

  <?php if ($events === []): ?>
  <div class="audit-empty">
    <span class="audit-empty-icon"><?= cream_icon('list-ul', 'icon-lg') ?></span>
    <strong>没有符合条件的记录</strong>
    <p>可以放宽时间范围、换一个事件名，或清空筛选后再看。</p>
    <a class="admin-button ghost" href="<?= cream_e($clearUrl) ?>">清空全部条件</a>
  </div>
  <?php else: ?>
  <div class="audit-timeline" role="list">
    <?php foreach ($events as $entry): ?>
    <article class="audit-entry audit-cat-<?= cream_e((string) ($entry['category'] ?? 'other')) ?>" role="listitem">
      <span class="audit-marker" aria-hidden="true"></span>
      <div class="audit-card">
        <header class="audit-card-head">
          <div class="audit-card-title">
            <span class="audit-cat-badge"><?= cream_e((string) ($entry['category_label'] ?? '')) ?></span>
            <strong><?= cream_e((string) ($entry['label'] ?? '')) ?></strong>
            <?php if (!empty($entry['failed'])): ?>
            <span class="audit-fail-badge"><?= cream_icon('alert', 'icon-xs') ?> 失败</span>
            <?php endif; ?>
          </div>
          <div class="audit-card-time">
            <time datetime="<?= cream_e((string) ($entry['datetime'] ?? '')) ?>" title="<?= cream_e((string) ($entry['created_at'] ?? '')) ?>"><?= cream_e((string) ($entry['relative_time'] ?? '')) ?></time>
            <small><?= cream_e((string) ($entry['absolute_time'] ?? '')) ?></small>
          </div>
        </header>

        <code class="audit-event-code"><?= cream_e((string) ($entry['event'] ?? '')) ?></code>

        <?php if ((string) ($entry['summary'] ?? '') !== ''): ?>
        <p class="audit-summary"><?= cream_e((string) ($entry['summary'] ?? '')) ?></p>
        <?php endif; ?>

        <div class="audit-meta-row">
          <span class="audit-actor"><?= cream_icon('planner', 'icon-xs') ?> <?= cream_e((string) ($entry['actor'] ?? '')) ?></span>
          <?php if ((string) ($entry['plan_id'] ?? '') !== ''): ?>
          <span class="audit-ref">计划 <code><?= cream_e((string) ($entry['plan_id'] ?? '')) ?></code></span>
          <?php endif; ?>
          <?php if ((string) ($entry['backup_id'] ?? '') !== ''): ?>
          <span class="audit-ref">备份 <code><?= cream_e((string) ($entry['backup_id'] ?? '')) ?></code></span>
          <?php endif; ?>
        </div>

        <?php if (is_array($entry['fields'] ?? null) && $entry['fields'] !== []): ?>
        <details class="audit-detail">
          <summary>
            <span>查看字段</span>
            <small><?= count($entry['fields']) ?> 项</small>
          </summary>
          <div class="audit-detail-body">
            <dl class="audit-field-grid">
              <?php foreach ($entry['fields'] as $field): ?>
              <dt><?= cream_e((string) ($field['label'] ?? '')) ?></dt>
              <dd>
                <?= cream_e((string) ($field['value'] ?? '')) ?>
                <?php if ((string) ($field['label'] ?? '') !== (string) ($field['key'] ?? '')): ?>
                <code><?= cream_e((string) ($field['key'] ?? '')) ?></code>
                <?php endif; ?>
              </dd>
              <?php endforeach; ?>
            </dl>
            <div class="audit-json">
              <span>原始 JSON · 已脱敏</span>
              <pre><?= cream_e((string) ($entry['payload_json'] ?? '{}')) ?></pre>
            </div>
          </div>
        </details>
        <?php else: ?>
        <span class="audit-no-payload">没有附加字段</span>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
  <nav class="audit-pagination" aria-label="审计分页">
    <?php if ($prevUrl !== null): ?>
    <a class="audit-page-step" href="<?= cream_e($prevUrl) ?>"><?= cream_icon('arrow-left', 'icon-xs') ?> 上一页</a>
    <?php else: ?>
    <span class="audit-page-step is-disabled"><?= cream_icon('arrow-left', 'icon-xs') ?> 上一页</span>
    <?php endif; ?>
    <div class="audit-page-numbers">
      <?php foreach ($pageLinks as $link): ?>
        <?php if (!empty($link['gap'])): ?>
        <span class="audit-page-gap" aria-hidden="true">…</span>
        <?php elseif (!empty($link['current'])): ?>
        <span class="audit-page-num is-current" aria-current="page"><?= (int) ($link['page'] ?? 0) ?></span>
        <?php else: ?>
        <a class="audit-page-num" href="<?= cream_e((string) ($link['url'] ?? $clearUrl)) ?>"><?= (int) ($link['page'] ?? 0) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php if ($nextUrl !== null): ?>
    <a class="audit-page-step" href="<?= cream_e($nextUrl) ?>">下一页 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    <?php else: ?>
    <span class="audit-page-step is-disabled">下一页 <?= cream_icon('arrow-right', 'icon-xs') ?></span>
    <?php endif; ?>
  </nav>
  <div class="audit-page-meta">第 <?= $page ?> 页，共 <?= $pages ?> 页 · 每页 <?= $perPage ?> 条</div>
  <?php endif; ?>

  <section class="audit-boundary" aria-labelledby="audit-boundary-title">
    <div class="audit-boundary-head">
      <span>AUDIT BOUNDARY</span>
      <h3 id="audit-boundary-title">能做什么，不能做什么</h3>
    </div>
    <div class="audit-boundary-grid">
      <article>
        <span class="audit-boundary-index">01 · 只读</span>
        <strong>后台不提供编辑与删除</strong>
        <p>记录写入后不能被后台改写，避免审计页本身成为新的风险入口。</p>
      </article>
      <article>
        <span class="audit-boundary-index">02 · 脱敏</span>
        <strong>敏感字段统一遮蔽</strong>
        <p>密码、令牌、Cookie、授权头、CSRF 和会话字段写入时脱敏，旧记录读取时也会遮蔽。</p>
      </article>
      <article>
        <span class="audit-boundary-index">03 · 范围</span>
        <strong>只合并数据库事件</strong>
        <p>升级与回滚的文件日志仍在服务器 <code>upgrade.log</code>，不强行混进本页；版本页负责升级任务进度。</p>
      </article>
      <article>
        <span class="audit-boundary-index">04 · 导出</span>
        <strong>单次最多 <?= $exportLimit ?> 条</strong>
        <p>当前筛选会原样带入导出，本次可导出 <?= $exportCount ?> 条；更长的周期请按时间分批导出。</p>
      </article>
      <article>
        <span class="audit-boundary-index">05 · 保留</span>
        <strong>不自动清理</strong>
        <p><?= $retentionDays ?> 天前有 <strong><?= $olderCount ?></strong> 条。需要清理时只能由服务器管理员执行 <code>bin/audit-prune.php --days=<?= $retentionDays ?> --confirm</code>，默认只试运行。</p>
      </article>
    </div>
  </section>
</section>
