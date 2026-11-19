<?php
$events = is_array($events ?? null) ? $events : [];
$eventNames = is_array($eventNames ?? null) ? $eventNames : [];
$filter = is_array($filter ?? null) ? $filter : [];
$total = (int) ($total ?? count($events));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$prevUrl = $prevUrl ?? null;
$nextUrl = $nextUrl ?? null;
$clearUrl = $clearUrl ?? '/admin/audit.html';
$hasFilter = $filter !== [];
?>
<section class="admin-page">
  <header class="admin-page-head">
    <div>
      <span class="eyebrow">AUDIT</span>
      <h1>审计日志</h1>
      <p>共 <?= $total ?> 条。只记录动作、对象 id、状态和 IP，不记录密码。</p>
    </div>
    <form class="audit-filters" method="get" action="/admin/audit.html">
      <label>事件
        <select name="event">
          <option value="">全部事件</option>
          <?php foreach ($eventNames as $name): ?>
          <option value="<?= cream_e($name) ?>" <?= ($filter['event'] ?? '') === $name ? 'selected' : '' ?>><?= cream_e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>操作者
        <input name="actor" maxlength="120" value="<?= cream_e($filter['actor'] ?? '') ?>" placeholder="精确用户名">
      </label>
      <button class="admin-button" type="submit">筛选</button>
      <?php if ($hasFilter): ?><a class="link-button" href="<?= cream_e($clearUrl) ?>">清空</a><?php endif; ?>
    </form>
  </header>

<?php if ($events === []): ?>
  <div class="admin-empty"><?= cream_icon('layers') ?><h2><?= $hasFilter ? '没有匹配记录' : '还没有记录' ?></h2><p><?= $hasFilter ? '换一个事件或操作者再试。' : '登录、发文、归档和升级完成后会出现在这里。' ?></p></div>
<?php else: ?>
  <div class="audit-list">
  <?php foreach ($events as $event): ?>
    <article class="audit-row">
      <div class="audit-head"><code><?= cream_e($event['event']) ?></code><time><?= cream_e($event['created_at']) ?></time></div>
      <div class="audit-meta">操作者：<?= cream_e($event['actor']) ?></div>
      <?php if ($event['payload'] !== []): ?><pre><?= cream_e(json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}') ?></pre><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="audit-pager" aria-label="审计分页">
    <?php if ($prevUrl !== null): ?><a class="admin-button ghost" href="<?= cream_e($prevUrl) ?>">上一页</a><?php endif; ?>
    <span>第 <?= $page ?> / <?= $pages ?> 页</span>
    <?php if ($nextUrl !== null): ?><a class="admin-button ghost" href="<?= cream_e($nextUrl) ?>">下一页</a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</section>
