<?php
$latest = is_array($latest ?? null) ? $latest : null;
$latestError = is_string($latestError ?? null) ? $latestError : null;
$job = is_array($job ?? null) ? $job : null;
$currentRelease = is_string($currentRelease ?? null) ? $currentRelease : null;
$updateAvailable = (bool) ($updateAvailable ?? false);
$ahead = (bool) ($ahead ?? false);
$canUpdate = (bool) ($canUpdate ?? false);
$helperAvailable = (bool) ($helperAvailable ?? false);
$jobActive = (bool) ($jobActive ?? false);
$queued = (bool) ($queued ?? false);
$currentVersion = $currentVersion ?? null;
$phpVersion = (string) ($phpVersion ?? PHP_VERSION);
$plugins = $currentVersion !== null ? $currentVersion->plugins() : [];
$themes = $currentVersion !== null ? $currentVersion->themes() : [];
$adminVersion = (string) ($plugins['admin'] ?? '—');
$themeVersion = (string) ($themes['cream'] ?? '—');
$jobStatus = (string) ($job['status'] ?? '');
$jobStep = (string) ($job['step'] ?? 'queued');
$stepMap = [
    'queued' => ['排队', 4],
    'checking' => ['检查发布', 14],
    'download' => ['下载发布包', 28],
    'verify' => ['校验签名', 44],
    'install' => ['安装文件', 58],
    'plan' => ['生成计划', 72],
    'apply' => ['备份并切换', 88],
    'done' => ['更新完成', 100],
];
[$stepLabel, $stepPercent] = $stepMap[$jobStep] ?? ['等待状态', 4];
$statusText = '已是最新';
$statusTone = 'calm';
if ($latest === null && $latestError !== null) {
    $statusText = '检查失败';
    $statusTone = 'bad';
} elseif ($updateAvailable) {
    $statusText = '可更新';
    $statusTone = 'hot';
} elseif ($ahead) {
    $statusText = '本地更高';
    $statusTone = 'calm';
} elseif ($latest === null) {
    $statusText = '暂无发布';
    $statusTone = 'calm';
}
if ($jobActive) {
    $statusText = '更新中';
    $statusTone = 'hot';
} elseif ($jobStatus === 'failed') {
    $statusText = '上次失败';
    $statusTone = 'bad';
} elseif ($jobStatus === 'done') {
    $statusText = '已更新';
    $statusTone = 'calm';
}
$published = $latest !== null && $latest['published_at'] !== '' ? cream_date($latest['published_at']) : '—';
$panelStatus = $jobActive || $queued ? 'running' : ($jobStatus === 'failed' ? 'failed' : 'idle');
$finishedAt = $jobStatus === 'done' && isset($job['finished_at']) && is_string($job['finished_at'])
    ? strtotime($job['finished_at'])
    : false;
$recentDone = $finishedAt !== false && time() - $finishedAt < 86400;
$flashText = $recentDone ? (string) ($job['message'] ?? '更新完成，站点已恢复访问。') : '';
$jobStartedAt = $job !== null && isset($job['started_at']) && is_string($job['started_at'])
    ? strtotime($job['started_at'])
    : false;
$jobFinishedAt = $job !== null && isset($job['finished_at']) && is_string($job['finished_at'])
    ? strtotime($job['finished_at'])
    : false;
$jobFinishedLabel = '—';
if ($jobFinishedAt !== false) {
    $elapsed = max(0, time() - $jobFinishedAt);
    if ($elapsed < 60) {
        $jobFinishedLabel = '刚刚';
    } elseif ($elapsed < 3600) {
        $jobFinishedLabel = (int) floor($elapsed / 60) . ' 分钟前';
    } elseif ($elapsed < 86400) {
        $jobFinishedLabel = (int) floor($elapsed / 3600) . ' 小时前';
    } else {
        $jobFinishedLabel = cream_date((string) ($job['finished_at'] ?? ''));
    }
}
$jobDuration = '—';
if ($jobStartedAt !== false && $jobFinishedAt !== false && $jobFinishedAt >= $jobStartedAt) {
    $seconds = $jobFinishedAt - $jobStartedAt;
    $jobDuration = $seconds < 60
        ? $seconds . ' 秒'
        : (int) floor($seconds / 60) . ' 分 ' . ($seconds % 60) . ' 秒';
}
$jobStatusLabel = $job === null
    ? '待命'
    : ($jobStatus === 'done' ? '已完成' : ($jobStatus === 'failed' ? '失败' : '进行中'));
$buttonLabel = '无需更新';
if ($canUpdate && $latest !== null) {
    $buttonLabel = '更新到 ' . $latest['tag'];
} elseif ($updateAvailable && !$helperAvailable) {
    $buttonLabel = '更新助手未安装';
} elseif ($updateAvailable && $jobActive) {
    $buttonLabel = '更新进行中';
} elseif ($updateAvailable) {
    $buttonLabel = '暂不可更新';
}
?>
<section class="admin-page update-page" id="update-panel"
  data-status-url="/admin/updates/status"
  data-panel-status="<?= cream_e($panelStatus) ?>"
  data-step="<?= cream_e($jobStep) ?>"
  data-step-percent="<?= (int) $stepPercent ?>">
  <div class="update-flash<?= $recentDone ? ' is-good' : ' is-hidden' ?>" id="update-flash" role="status" aria-live="polite"<?= $recentDone ? '' : ' hidden' ?>>
    <?= cream_e($flashText) ?>
  </div>
  <header class="admin-page-head update-head">
    <div>
      <span class="eyebrow">RELEASES · BACKSTAGE</span>
      <p>看当前版本、检查 GitHub 正式发布，并由服务器受控助手完成备份与切换。</p>
    </div>
    <a class="admin-button ghost" href="/admin/updates.html"><?= cream_icon('refresh', 'icon-xs') ?> 检查更新</a>
  </header>

  <div class="update-hero">
    <article class="update-card update-current" data-update-region="current">
      <div class="update-card-top">
        <span class="update-kicker">CURRENT</span>
        <span class="update-state <?= cream_e($statusTone) ?>"><?= cream_e($statusText) ?></span>
      </div>
      <strong class="update-version"><?= cream_e($currentVersion !== null ? $currentVersion->app() : '—') ?></strong>
      <p class="update-channel"><?= cream_e($currentVersion !== null ? $currentVersion->channel() : 'stable') ?> · <?= $currentRelease !== null ? cream_e($currentRelease) : '源码目录' ?></p>
      <dl class="update-facts">
        <div><dt>后台</dt><dd><?= cream_e($adminVersion) ?></dd></div>
        <div><dt>主题</dt><dd>cream <?= cream_e($themeVersion) ?></dd></div>
        <div><dt>数据结构</dt><dd><?= $currentVersion !== null ? (int) $currentVersion->schema() : '—' ?></dd></div>
        <div><dt>PHP</dt><dd><?= cream_e($phpVersion) ?></dd></div>
      </dl>
    </article>

    <article class="update-card update-latest">
      <div class="update-card-top">
        <span class="update-kicker">GITHUB · LATEST</span>
        <?php if ($latest !== null): ?>
        <a class="update-link" href="<?= cream_e($latest['html_url']) ?>" target="_blank" rel="noopener">发布页 <?= cream_icon('external', 'icon-xs') ?></a>
        <?php endif; ?>
      </div>
      <?php if ($latest !== null): ?>
      <strong class="update-version"><?= cream_e($latest['app']) ?></strong>
      <p class="update-channel"><?= cream_e($latest['tag']) ?> · <?= cream_e($published) ?> · <?= number_format((int) $latest['zip']['size'] / 1024, 0) ?> KB</p>
      <?php if ($latest['body'] !== ''): ?>
      <div class="update-notes"><?= cream_update_notes((string) $latest['body']) ?></div>
      <?php else: ?>
      <p class="update-notes muted">这次发布没有填写说明。</p>
      <?php endif; ?>
      <?php elseif ($latestError !== null): ?>
      <strong class="update-version update-error-version">无法检查</strong>
      <p class="update-channel"><?= cream_e($latestError) ?></p>
      <p class="update-notes muted">版本页本身仍可使用，稍后刷新再试。</p>
      <?php else: ?>
      <strong class="update-version update-error-version">暂无发布</strong>
      <p class="update-notes muted">GitHub 上还没有正式 Release。</p>
      <?php endif; ?>
    </article>
  </div>

  <article class="update-action" data-update-region="action">
    <div class="update-action-copy">
      <?php if ($updateAvailable && $latest !== null): ?>
      <span class="update-action-eyebrow">READY TO ROLL</span>
      <h2>更新到 <?= cream_e($latest['app']) ?></h2>
      <p>助手会先备份数据库与配置，再迁移、切换 release 并检查站点。整个过程通常不到一分钟。</p>
      <?php elseif ($ahead): ?>
      <span class="update-action-eyebrow">LOCAL AHEAD</span>
      <h2>本地版本高于线上发布</h2>
      <p>当前代码来自更新的提交或未发布版本，页面不会把线上旧包装回来。</p>
      <?php else: ?>
      <span class="update-action-eyebrow">ALL CLEAR</span>
      <h2>已经是最新版本</h2>
      <p>GitHub 上最新的正式发布与当前版本一致，不需要更新。</p>
      <?php endif; ?>
    </div>
    <div class="update-action-side">
      <?php if (!$helperAvailable): ?>
      <p class="update-helper-note"><?= cream_icon('lock', 'icon-xs') ?> 更新助手未安装或不可执行，一键更新暂不可用。</p>
      <?php elseif ($jobActive): ?>
      <p class="update-helper-note"><?= cream_icon('clock', 'icon-xs') ?> 更新任务正在运行，请等待完成。</p>
      <?php endif; ?>
      <button class="admin-button primary update-start" type="button" data-update-open
        <?= $canUpdate ? '' : 'disabled' ?>>
        <?= cream_icon('refresh', 'icon-xs') ?>
        <?= cream_e($buttonLabel) ?>
      </button>
      <span class="update-safety">SHA256 校验 · 自动备份 · 失败保留计划</span>
    </div>
  </article>

  <article class="update-progress-card<?= $job === null ? ' is-empty' : '' ?>" id="update-progress" data-update-region="progress">
    <div class="update-progress-head">
      <div>
        <span class="update-kicker">UPDATE JOB</span>
        <strong id="update-step-label"><?= cream_e($job === null ? '还没有更新任务' : $stepLabel) ?></strong>
      </div>
      <span class="update-job-status <?= cream_e($jobStatus === 'failed' ? 'bad' : ($jobStatus === 'done' ? 'good' : '')) ?>" id="update-job-status">
        <?= cream_e($job === null ? '待命' : ($jobStatus === 'done' ? '已完成' : ($jobStatus === 'failed' ? '失败' : '进行中'))) ?>
      </span>
    </div>
    <div class="update-progress-track" aria-hidden="true">
      <span id="update-progress-bar" style="width: <?= $job !== null ? (int) $stepPercent : 0 ?>%"></span>
    </div>
    <div class="update-progress-meta">
      <span id="update-progress-message"><?= cream_e($job['message'] ?? '发布后，这里会显示下载、校验和切换进度。') ?></span>
      <?php if ($job !== null && isset($job['tag']) && is_string($job['tag'])): ?>
      <code><?= cream_e($job['tag']) ?></code>
      <?php endif; ?>
    </div>
    <div class="update-error<?= $jobStatus === 'failed' ? '' : ' is-hidden' ?>" id="update-error">
      <?= cream_e((string) ($job['error'] ?? '')) ?>
    </div>
  </article>

  <div class="update-details" data-update-region="details">
    <details class="update-detail" open>
      <summary>版本构成</summary>
      <div class="update-detail-body">
        <div class="update-version-grid">
          <div><span>应用</span><strong><?= cream_e($currentVersion !== null ? $currentVersion->app() : '—') ?></strong></div>
          <div><span>API</span><strong><?= cream_e($currentVersion !== null ? $currentVersion->api() : '—') ?></strong></div>
          <div><span>后台</span><strong><?= cream_e($adminVersion) ?></strong></div>
          <div><span>主题</span><strong>cream <?= cream_e($themeVersion) ?></strong></div>
          <div><span>schema</span><strong><?= $currentVersion !== null ? (int) $currentVersion->schema() : '—' ?></strong></div>
          <div><span>schema_min</span><strong><?= $currentVersion !== null ? (int) $currentVersion->schemaMin() : '—' ?></strong></div>
        </div>
      </div>
    </details>

    <div class="update-info-grid">
      <section class="update-info-card update-flow-card">
        <div class="update-info-head">
          <span class="update-kicker">WHAT HAPPENS</span>
          <h3>这次更新会做什么</h3>
          <p>从读取正式发布到站点恢复，整条链路都在服务器受控执行。</p>
        </div>
        <ol class="update-flow">
          <li>
            <span class="update-flow-num">01</span>
            <strong>检查发布</strong>
            <span>读取 GitHub 上最新的正式 Release。</span>
          </li>
          <li>
            <span class="update-flow-num">02</span>
            <strong>校验发布包</strong>
            <span>核对 SHA256、文件清单和版本契约。</span>
          </li>
          <li>
            <span class="update-flow-num">03</span>
            <strong>自动备份</strong>
            <span>备份数据库与共享配置，保留恢复计划。</span>
          </li>
          <li>
            <span class="update-flow-num">04</span>
            <strong>切换版本</strong>
            <span>进入维护模式，迁移并原子切换 release。</span>
          </li>
          <li>
            <span class="update-flow-num">05</span>
            <strong>健康检查</strong>
            <span>重启运行环境，确认站点恢复并写回结果。</span>
          </li>
        </ol>
        <p class="update-flow-footnote">通常不到一分钟完成；切换的几秒会短暂进入维护模式，当前页面会自动继续确认结果。</p>
      </section>

      <section class="update-info-card update-last-card">
        <div class="update-info-head">
          <span class="update-kicker">LAST UPDATE</span>
          <h3>最近一次更新</h3>
        </div>
        <?php if ($job === null): ?>
        <p class="update-empty-note">还没有后台更新记录。第一次更新后，这里会显示版本变化、耗时和备份编号。</p>
        <?php else: ?>
        <div class="update-last-summary">
          <div class="update-last-status">
            <span>任务状态</span>
            <strong class="<?= cream_e($jobStatus === 'failed' ? 'bad' : ($jobStatus === 'done' ? 'good' : '')) ?>"><?= cream_e($jobStatusLabel) ?></strong>
          </div>
          <div class="update-last-version">
            <span><?= cream_e((string) ($job['from'] ?? '—')) ?></span>
            <i aria-hidden="true">→</i>
            <strong><?= cream_e((string) ($job['to'] ?? '—')) ?></strong>
          </div>
          <dl class="update-last-facts">
            <div>
              <dt>完成时间</dt>
              <dd><?= cream_e($jobFinishedLabel) ?></dd>
            </div>
            <div>
              <dt>耗时</dt>
              <dd><?= cream_e($jobDuration) ?></dd>
            </div>
            <div>
              <dt>目标版本</dt>
              <dd><code><?= cream_e((string) ($job['tag'] ?? '—')) ?></code></dd>
            </div>
          </dl>
          <?php if (isset($job['backup_id']) && is_string($job['backup_id']) && $job['backup_id'] !== ''): ?>
          <div class="update-backup-id">
            <span>备份编号</span>
            <code><?= cream_e($job['backup_id']) ?></code>
          </div>
          <?php endif; ?>
          <?php if ($jobStatus === 'failed' && !empty($job['error'])): ?>
          <p class="update-last-error"><?= cream_e((string) $job['error']) ?></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>
    </div>

    <section class="update-info-card update-guard-card">
      <div class="update-info-head">
        <span class="update-kicker">SAFETY NET</span>
        <h3>升级保障</h3>
        <p>每一步都可核对；失败时不删除备份，也不会把站点停在未知状态。</p>
      </div>
      <div class="update-guard-grid">
        <div><strong>仅正式版</strong><span>草稿和预发布不会进入更新。</span></div>
        <div><strong>SHA256 校验</strong><span>下载包与校验文件必须完全一致。</span></div>
        <div><strong>自动备份</strong><span>数据库和共享配置先备份再切换。</span></div>
        <div><strong>独立任务</strong><span>重启 PHP-FPM 不会打断更新助手。</span></div>
        <div><strong>健康检查</strong><span>切换后确认站点和 API 正常响应。</span></div>
        <div><strong>失败可恢复</strong><span>保留计划与备份，可用 CLI 恢复。</span></div>
      </div>
    </section>
  </div>
</section>

<dialog class="update-dialog" id="update-dialog">
  <div class="update-dialog-body">
    <span class="update-kicker">CONFIRM UPDATE</span>
    <h2>更新到 <?= cream_e($latest['tag'] ?? '') ?>？</h2>
    <p>更新会短暂进入维护模式，并自动备份数据库和共享配置。失败时会保留升级计划和备份，不会自动删除。</p>
    <div class="update-dialog-actions">
      <button class="admin-button ghost" type="button" data-update-cancel>取消</button>
      <form method="post" action="/admin/updates/apply" class="inline-form" id="update-apply-form">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <input type="hidden" name="tag" value="<?= cream_e($latest['tag'] ?? '') ?>">
        <button class="admin-button primary" type="submit">开始更新</button>
      </form>
    </div>
  </div>
</dialog>
<noscript><p class="alert bad">一键更新需要 JavaScript 确认；也可以继续使用服务器上的 upgrade.php。</p></noscript>
