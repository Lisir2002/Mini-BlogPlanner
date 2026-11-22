<?php
$app = (string) ($app ?? '0.1.0');
$siteTitle = (string) ($site['title'] ?? '日记Planner');
$schema = (string) ($schema ?? '');
?>
<footer class="site-footer">
  <div class="footer-scallop" aria-hidden="true"></div>
  <div class="footer-inner">
    <div class="footer-brand">
      <span class="footer-mark" aria-hidden="true"><?= cream_icon('planner', 'icon-sm') ?></span>
      <div class="footer-brand-copy">
        <p class="footer-note"><strong><?= cream_e($siteTitle) ?></strong></p>
        <p class="footer-tagline">把日子写进计划里。</p>
      </div>
    </div>
    <nav class="footer-links" aria-label="站点入口">
      <a class="footer-link" href="/feed.xml"><?= cream_icon('rss', 'icon-xs') ?><span>RSS</span></a>
      <a class="footer-link" href="/status.html"><?= cream_icon('shield', 'icon-xs') ?><span>状态</span></a>
    </nav>
    <p class="footer-meta">
      <span class="footer-product">Mini-BlogPlanner v<?= cream_e($app) ?></span>
      <?php if ($schema !== ''): ?><span class="footer-sep" aria-hidden="true">·</span><span>schema <?= cream_e($schema) ?></span><?php endif; ?>
    </p>
  </div>
</footer>
