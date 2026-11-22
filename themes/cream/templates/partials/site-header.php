<?php
$current = (string) ($current ?? 'home');
$siteTitle = (string) ($site['title'] ?? '日记Planner');
$pageHeading = trim((string) ($pageHeading ?? ''));
if ($pageHeading === '') {
    $pageHeading = $siteTitle;
}
$searchEnabled = empty($error_status);
?>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="<?= cream_e($siteTitle) ?> 首页">
      <span class="brand-mark"><?= cream_icon('planner', 'icon-brand') ?></span>
      <div class="brand-copy">
        <span class="brand-project">MINI-BLOGPLANNER</span>
        <h1 class="brand-text" title="<?= cream_e($pageHeading) ?>"><span class="brand-page-title"><?= cream_e($pageHeading) ?></span><span class="brand-dot" aria-hidden="true"></span></h1>
      </div>
    </a>

    <nav class="nav nav-desktop" aria-label="主导航">
      <a class="nav-link<?= $current === 'home' ? ' is-current' : '' ?>" href="/"<?= $current === 'home' ? ' aria-current="page"' : '' ?>>首页</a>
      <a class="nav-link<?= $current === 'posts' ? ' is-current' : '' ?>" href="/posts.html"<?= $current === 'posts' ? ' aria-current="page"' : '' ?>>文章</a>
      <a class="nav-link<?= $current === 'archive' ? ' is-current' : '' ?>" href="/archive.html"<?= $current === 'archive' ? ' aria-current="page"' : '' ?>>归档</a>
      <a class="nav-link<?= $current === 'tags' ? ' is-current' : '' ?>" href="/tags.html"<?= $current === 'tags' ? ' aria-current="page"' : '' ?>>标签</a>
      <a class="nav-link<?= $current === 'about' ? ' is-current' : '' ?>" href="/about.html"<?= $current === 'about' ? ' aria-current="page"' : '' ?>>关于</a>
      <a class="nav-link<?= $current === 'status' ? ' is-current' : '' ?>" href="/status.html"<?= $current === 'status' ? ' aria-current="page"' : '' ?>>站点状态</a>
    </nav>

    <div class="header-actions">
<?php if ($searchEnabled): ?>
      <button class="nav-icon-link search-trigger" type="button" aria-label="搜索文章" aria-expanded="false" aria-controls="search-overlay" data-search-open>
        <?= cream_icon('search', 'icon-sm') ?>
      </button>
<?php endif; ?>

      <details class="nav-mobile">
      <summary aria-label="打开导航菜单">
        <span class="nav-icon-open"><?= cream_icon('menu') ?></span>
        <span class="nav-icon-close"><?= cream_icon('close') ?></span>
      </summary>
      <span class="nav-mobile-scrim" aria-hidden="true"></span>
      <nav class="nav-mobile-panel" aria-label="移动导航">
        <div class="nav-mobile-sheet">
          <div class="nav-drawer-head">
            <span class="nav-drawer-eyebrow">NAVIGATION</span>
            <strong class="nav-drawer-title">目录</strong>
            <p class="nav-drawer-sub">挑一页，继续翻。</p>
          </div>
          <div class="nav-drawer-links">
            <a class="nav-drawer-link<?= $current === 'home' ? ' is-current' : '' ?>" href="/"<?= $current === 'home' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('planner', 'icon-sm') ?></span>
              <span class="nav-drawer-text">首页</span>
              <span class="nav-drawer-index">01</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'posts' ? ' is-current' : '' ?>" href="/posts.html"<?= $current === 'posts' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('book', 'icon-sm') ?></span>
              <span class="nav-drawer-text">文章</span>
              <span class="nav-drawer-index">02</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'archive' ? ' is-current' : '' ?>" href="/archive.html"<?= $current === 'archive' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('calendar', 'icon-sm') ?></span>
              <span class="nav-drawer-text">归档</span>
              <span class="nav-drawer-index">03</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'tags' ? ' is-current' : '' ?>" href="/tags.html"<?= $current === 'tags' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('tag', 'icon-sm') ?></span>
              <span class="nav-drawer-text">标签</span>
              <span class="nav-drawer-index">04</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'about' ? ' is-current' : '' ?>" href="/about.html"<?= $current === 'about' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('heart', 'icon-sm') ?></span>
              <span class="nav-drawer-text">关于</span>
              <span class="nav-drawer-index">05</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'status' ? ' is-current' : '' ?>" href="/status.html"<?= $current === 'status' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('shield', 'icon-sm') ?></span>
              <span class="nav-drawer-text">站点状态</span>
              <span class="nav-drawer-index">06</span>
            </a>
          </div>
          <div class="nav-drawer-foot">
            <span>MINI-BLOGPLANNER</span>
          </div>
        </div>
      </nav>
      </details>
    </div>
  </div>
</header>
<?php if ($searchEnabled) { require __DIR__ . '/search-overlay.php'; } ?>
