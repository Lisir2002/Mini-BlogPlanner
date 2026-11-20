<?php
$app = (string) ($app ?? '0.1.0');
$siteTitle = (string) ($site['title'] ?? '日记Planner');
$schema = (string) ($schema ?? '');
?>
<footer class="site-footer">
  <div class="footer-scallop" aria-hidden="true"></div>
  <div class="footer-inner">
    <p class="footer-note"><?= cream_icon('planner', 'icon-sm') ?> <strong><?= cream_e($siteTitle) ?></strong> · 把日子写进计划里。</p>
    <p class="footer-meta">
      Mini-BlogPlanner v<?= cream_e($app) ?><?php if ($schema !== ''): ?> · schema <?= cream_e($schema) ?><?php endif; ?>
      · <a class="footer-admin" href="/admin/login.html"><?= cream_icon('lock', 'icon-xs') ?> 后台</a>
      · <a href="/feed.xml"><?= cream_icon('rss', 'icon-xs') ?> RSS</a>
      · <a href="/api.html">API</a>
      · <a href="/healthz">healthz</a>
    </p>
  </div>
</footer>
