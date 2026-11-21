<?php
$latest = is_array($latest ?? null) ? $latest : null;
$latestError = is_string($latestError ?? null) ? $latestError : null;
$job = is_array($job ?? null) ? $job : null;
$releaseHistory = is_array($releaseHistory ?? null) ? $releaseHistory : [];
$currentRelease = is_string($currentRelease ?? null) ? $currentRelease : null;
$previousRelease = is_string($previousRelease ?? null) ? $previousRelease : null;
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

    <details class="update-detail">
      <summary>已部署 release</summary>
      <div class="update-detail-body">
        <?php if ($releaseHistory === []): ?>
        <p class="muted">当前环境没有 releases 目录，正在以源码目录运行。</p>
        <?php else: ?>
        <div class="update-release-list">
          <?php foreach ($releaseHistory as $release): ?>
          <div class="update-release-row">
            <code><?= cream_e($release) ?></code>
            <span><?= $release === $currentRelease ? '当前' : ($release === $previousRelease ? '上一版' : '') ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </details>
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
