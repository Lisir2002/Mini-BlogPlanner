<?php
$report = is_array($report ?? null) ? $report : [];
$status = (string) ($report['status'] ?? 'degraded');
$healthy = $status === 'ok';
$checkedAt = (string) ($report['checked_at'] ?? '');
$checkedLabel = $checkedAt === '' ? '刚刚' : date('Y.m.d H:i', (int) strtotime($checkedAt));
$app = (string) ($report['app'] ?? ($app ?? ''));
$api = (string) ($report['api'] ?? '');
$channel = (string) ($report['channel'] ?? '');
$schema = $report['schema'] ?? null;
$latency = (int) ($report['latency_ms'] ?? 0);
$database = is_array($report['database'] ?? null) ? $report['database'] : [];
$content = is_array($report['content'] ?? null) ? $report['content'] : [];
$checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
$php = is_array($report['php'] ?? null) ? $report['php'] : [];
$contentCards = [
    ['label' => '已发布文章', 'value' => (int) ($content['published_posts'] ?? 0), 'icon' => 'book'],
    ['label' => '已通过留言', 'value' => (int) ($content['approved_comments'] ?? 0), 'icon' => 'comment'],
    ['label' => '媒体图片', 'value' => (int) ($content['media'] ?? 0), 'icon' => 'image'],
    ['label' => '内容标签', 'value' => (int) ($content['tags'] ?? 0), 'icon' => 'tag'],
];
$lastPublished = (string) ($content['last_published_at'] ?? '');
?>
<section class="status-page">
  <header class="status-hero">
    <span class="tape tape-mint status-tape" aria-hidden="true"></span>
    <div class="status-hero-copy">
      <span class="eyebrow"><?= cream_icon($healthy ? 'leaf' : 'alert', 'icon-sm') ?> SITE STATUS</span>
      <h2>这本手账，<br><span class="marker">今天状态如何。</span></h2>
      <p>版本、数据库、结构契约和内容概览都在这里。这里只读，不提供修改入口。</p>
      <div class="status-hero-badge<?= $healthy ? ' is-good' : ' is-bad' ?>">
        <span class="status-dot" aria-hidden="true"></span>
        <strong><?= $healthy ? '运行正常' : '需要关注' ?></strong>
        <span>检查于 <?= cream_e($checkedLabel) ?> UTC</span>
      </div>
    </div>
    <aside class="status-stamp" aria-label="当前发布">
      <span class="status-stamp-kicker">CURRENT</span>
      <strong>v<?= cream_e($app) ?></strong>
      <span><?= cream_e($channel) ?> · schema <?= cream_e((string) ($schema ?? '—')) ?></span>
    </aside>
  </header>

  <div class="status-actions">
    <a class="button button-primary" href="/status.html"><?= cream_icon('refresh') ?> 重新检查</a>
    <a class="button button-ghost" href="/api/v1/status" target="_blank" rel="noopener"><?= cream_icon('plug') ?> 查看 JSON</a>
  </div>

  <div class="status-metrics">
    <article class="status-metric">
      <span class="status-metric-icon"><?= cream_icon('layers') ?></span>
      <span class="status-metric-label">应用版本</span>
      <strong>v<?= cream_e($app) ?></strong>
      <small><?= cream_e($channel) ?> 通道</small>
    </article>
    <article class="status-metric">
      <span class="status-metric-icon"><?= cream_icon('plug') ?></span>
      <span class="status-metric-label">API 版本</span>
      <strong><?= cream_e($api) ?></strong>
      <small>公开可读接口</small>
    </article>
    <article class="status-metric">
      <span class="status-metric-icon"><?= cream_icon('clock') ?></span>
      <span class="status-metric-label">响应延迟</span>
      <strong><?= $latency ?> <em>ms</em></strong>
      <small>本次状态汇总</small>
    </article>
    <article class="status-metric">
      <span class="status-metric-icon"><?= cream_icon('calendar') ?></span>
      <span class="status-metric-label">最近发布</span>
      <strong><?= $lastPublished === '' ? '暂无' : cream_e(cream_date($lastPublished)) ?></strong>
      <small>按发布时间</small>
    </article>
  </div>

  <section class="status-section">
    <header class="status-section-head">
      <div>
        <span class="eyebrow">CHECKS</span>
        <h3>关键检查</h3>
      </div>
      <p>只显示公开可读的运行信号。</p>
    </header>
    <div class="status-checks">
      <?php foreach ($checks as $check): ?>
      <?php $checkStatus = (string) ($check['status'] ?? 'ok'); ?>
      <article class="status-check status-check--<?= cream_e($checkStatus) ?>">
        <span class="status-check-mark"><?= cream_icon($checkStatus === 'ok' ? 'check' : 'alert', 'icon-sm') ?></span>
        <div>
          <strong><?= cream_e((string) ($check['label'] ?? '') ) ?></strong>
          <p><?= cream_e((string) ($check['detail'] ?? '')) ?></p>
        </div>
        <span class="status-check-metric"><?= cream_e((string) ($check['metric'] ?? '')) ?></span>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="status-section">
    <header class="status-section-head">
      <div>
        <span class="eyebrow">CONTENT</span>
        <h3>内容概览</h3>
      </div>
      <p>只统计已经对外可见的内容。</p>
    </header>
    <div class="status-content-grid">
      <?php foreach ($contentCards as $card): ?>
      <article class="status-content-card">
        <span class="status-content-icon"><?= cream_icon((string) $card['icon']) ?></span>
        <strong><?= number_format((int) $card['value']) ?></strong>
        <span><?= cream_e((string) $card['label']) ?></span>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="status-section status-runtime">
    <header class="status-section-head">
      <div>
        <span class="eyebrow">RUNTIME</span>
        <h3>运行环境</h3>
      </div>
      <p>不显示连接地址、账号或服务器路径。</p>
    </header>
    <dl class="status-runtime-list">
      <div><dt>PHP</dt><dd><?= cream_e((string) ($php['version'] ?? '')) ?> · <?= cream_e((string) ($php['sapi'] ?? '')) ?></dd></div>
      <div><dt>时区</dt><dd><?= cream_e((string) ($php['timezone'] ?? '')) ?></dd></div>
      <div><dt>数据库</dt><dd><?= !empty($database['connected']) ? '已连接' : '未连接' ?><?php if (!empty($database['version'])): ?> · <?= cream_e((string) $database['version']) ?><?php endif; ?></dd></div>
      <div><dt>数据库时间</dt><dd><?= cream_e((string) ($database['server_time'] ?? '—')) ?></dd></div>
    </dl>
  </section>

  <footer class="status-note">
    <span class="status-note-icon"><?= cream_icon('shield') ?></span>
    <div>
      <strong>这是只读状态页</strong>
      <p>探针请继续使用 <a href="/healthz">/healthz</a>，需要机器可读的完整字段请访问 <a href="/api/v1/status">/api/v1/status</a>。状态页不提供任何后台操作入口。</p>
    </div>
  </footer>
</section>
