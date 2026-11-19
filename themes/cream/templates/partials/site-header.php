<?php
$current = (string) ($current ?? 'home');
$siteTitle = (string) ($site['title'] ?? '日记Planner');
?>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="<?= cream_e($siteTitle) ?> 首页">
      <span class="brand-mark"><?= cream_icon('planner', 'icon-brand') ?></span>
      <span class="brand-copy">
        <span class="brand-project">MINI-BLOGPLANNER</span>
        <span class="brand-text"><?= cream_e($siteTitle) ?><span class="brand-dot" aria-hidden="true"></span></span>
      </span>
    </a>

    <nav class="nav nav-desktop" aria-label="主导航">
      <a class="nav-link<?= $current === 'home' ? ' is-current' : '' ?>" href="/">首页</a>
      <a class="nav-link<?= $current === 'posts' ? ' is-current' : '' ?>" href="/posts.html">文章</a>
      <a class="nav-link<?= $current === 'tags' ? ' is-current' : '' ?>" href="/tags.html">标签</a>
      <a class="nav-link" href="/about.html">关于</a>
      <a class="nav-link" href="/api.html">接口文档</a>
      <a class="nav-link" href="/healthz">站点状态</a>
    </nav>

    <details class="nav-mobile">
      <summary aria-label="打开导航"><?= cream_icon('menu') ?></summary>
      <nav class="nav-mobile-panel" aria-label="移动导航">
        <a href="/">首页</a>
        <a href="/posts.html">文章</a>
        <a href="/tags.html">标签</a>
        <a href="/about.html">关于</a>
        <a href="/api.html">接口文档</a>
        <a href="/healthz">站点状态</a>
      </nav>
    </details>
  </div>
</header>
