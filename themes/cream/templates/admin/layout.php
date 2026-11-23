<?php
$admin = $admin ?? null;
$title = (string) ($title ?? '后台');
$csrf = (string) ($csrf ?? '');
$current = (string) ($current ?? '');
$siteTitle = (string) (($site['title'] ?? '日记Planner'));
$headerTitle = trim((string) ($title ?? ''));
if ($headerTitle === '') {
    $headerTitle = '后台';
}
$adminSections = \Core\Admin\AdminNavigation::all();
$loginLabel = \Core\Admin\AdminNavigation::LOGIN_LABEL;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= cream_e($title) ?> · <?= cream_e($siteTitle) ?></title>
<?php require dirname(__DIR__) . '/partials/loader-style.php'; ?>
<link rel="preload" href="<?= cream_asset('icons.svg') ?>" as="image" type="image/svg+xml" fetchpriority="low">
<link rel="stylesheet" href="<?= cream_asset('cream.css') ?>" fetchpriority="high">
<link rel="stylesheet" href="<?= cream_asset('admin.css') ?>" fetchpriority="high">
<?php foreach (($page_styles ?? []) as $style): ?>
<link rel="stylesheet" href="<?= cream_e(str_starts_with((string) $style, '/') ? (string) $style : cream_asset((string) $style)) ?>">
<?php endforeach; ?>
<link rel="icon" href="<?= cream_asset('favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="admin-body<?= $current === 'login' ? ' admin-login-body' : '' ?><?= isset($body_class) ? ' ' . cream_e((string) $body_class) : '' ?>">
<?php require dirname(__DIR__) . '/partials/loader.php'; ?>
<a class="skip-link" href="#admin-main">跳到正文</a>
<header class="admin-header">
  <div class="admin-header-inner">
    <a class="admin-brand" href="/admin">
      <span class="admin-brand-mark"><?= cream_icon('planner') ?></span>
      <div class="admin-brand-copy"><small>MINI-BLOGPLANNER</small><h1 class="admin-brand-title" title="<?= cream_e($headerTitle) ?>"><?= cream_e($headerTitle) ?></h1></div>
    </a>
    <?php if ($admin !== null): ?>
    <nav class="admin-nav nav-desktop" aria-label="后台导航">
      <?php foreach ($adminSections as $key => $section): ?>
      <a class="<?= $current === $key ? 'is-current' : '' ?>" href="<?= cream_e($section['href']) ?>"><?= cream_e($section['label']) ?></a>
      <?php endforeach; ?>
      <a href="/" target="_blank" rel="noopener">查看站点 <?= cream_icon('external', 'icon-xs') ?></a>
      <span class="admin-user"><?= cream_e($admin->username) ?></span>
      <form method="post" action="/admin/logout.html" class="inline-form" data-ajax-full>
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <button class="link-button" type="submit">退出</button>
      </form>
    </nav>

    <details class="nav-mobile admin-mobile">
      <summary aria-label="打开后台菜单">
        <span class="nav-icon-open"><?= cream_icon('menu') ?></span>
        <span class="nav-icon-close"><?= cream_icon('close') ?></span>
      </summary>
      <span class="nav-mobile-scrim admin-mobile-scrim" aria-hidden="true"></span>
      <nav class="nav-mobile-panel admin-mobile-panel" aria-label="后台移动导航">
        <div class="nav-mobile-sheet admin-mobile-sheet">
          <div class="nav-drawer-head">
            <span class="nav-drawer-eyebrow">BACKSTAGE · <?= cream_e($admin->username) ?></span>
            <strong class="nav-drawer-title">后台目录</strong>
            <p class="nav-drawer-sub">选一项，继续写。</p>
          </div>
          <div class="nav-drawer-links">
            <?php $sectionNumber = 0; foreach ($adminSections as $key => $section): $sectionNumber += 1; ?>
            <a class="nav-drawer-link<?= $current === $key ? ' is-current' : '' ?>" href="<?= cream_e($section['href']) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon($section['icon'], 'icon-sm') ?></span>
              <span class="nav-drawer-text"><?= cream_e($section['label']) ?></span>
              <span class="nav-drawer-index"><?= str_pad((string) $sectionNumber, 2, '0', STR_PAD_LEFT) ?></span>
            </a>
            <?php endforeach; ?>
            <?php $sectionNumber += 1; ?>
            <a class="nav-drawer-link" href="/" target="_blank" rel="noopener">
              <span class="nav-drawer-icon"><?= cream_icon('external', 'icon-sm') ?></span>
              <span class="nav-drawer-text">查看站点</span>
              <span class="nav-drawer-index"><?= str_pad((string) $sectionNumber, 2, '0', STR_PAD_LEFT) ?></span>
            </a>
          </div>
          <div class="nav-drawer-foot admin-mobile-foot">
            <span>MINI-BLOGPLANNER</span>
            <form method="post" action="/admin/logout.html" class="admin-mobile-logout" data-ajax-full>
              <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
              <button type="submit"><?= cream_icon('arrow-left', 'icon-xs') ?> 退出登录</button>
            </form>
          </div>
        </div>
      </nav>
    </details>
    <?php else: ?>
    <nav class="admin-nav nav-desktop" aria-label="后台登录导航">
      <a class="is-current" href="/admin/login.html"><?= cream_e($loginLabel) ?></a>
      <a href="/">返回站点 <?= cream_icon('external', 'icon-xs') ?></a>
    </nav>
    <details class="nav-mobile admin-mobile">
      <summary aria-label="打开后台登录菜单">
        <span class="nav-icon-open"><?= cream_icon('menu') ?></span>
        <span class="nav-icon-close"><?= cream_icon('close') ?></span>
      </summary>
      <span class="nav-mobile-scrim admin-mobile-scrim" aria-hidden="true"></span>
      <nav class="nav-mobile-panel admin-mobile-panel" aria-label="后台登录移动导航">
        <div class="nav-mobile-sheet admin-mobile-sheet">
          <div class="nav-drawer-head">
            <span class="nav-drawer-eyebrow">BACKSTAGE · PRIVATE</span>
            <strong class="nav-drawer-title">后台目录</strong>
            <p class="nav-drawer-sub">登录后继续。</p>
          </div>
          <div class="nav-drawer-links">
            <a class="nav-drawer-link is-current" href="/admin/login.html" aria-current="page">
              <span class="nav-drawer-icon"><?= cream_icon('lock', 'icon-sm') ?></span>
              <span class="nav-drawer-text"><?= cream_e($loginLabel) ?></span>
              <span class="nav-drawer-index">01</span>
            </a>
            <a class="nav-drawer-link" href="/">
              <span class="nav-drawer-icon"><?= cream_icon('arrow-left', 'icon-sm') ?></span>
              <span class="nav-drawer-text">返回站点</span>
              <span class="nav-drawer-index">02</span>
            </a>
          </div>
          <div class="nav-drawer-foot">
            <span>MINI-BLOGPLANNER</span>
          </div>
        </div>
      </nav>
    </details>
    <?php endif; ?>
  </div>
</header>
<main id="admin-main" class="admin-main">
<?= $content ?>
</main>
<script src="<?= cream_asset('cream.js') ?>" defer onerror="document.getElementById('cream-loader').hidden=true"></script>
<script src="<?= cream_asset('admin-viewport.js') ?>" defer></script>
<script src="<?= cream_asset('admin-select.js') ?>" defer></script>
<script src="<?= cream_asset('admin-filters.js') ?>" defer></script>
<?php foreach (($page_scripts ?? []) as $script): ?>
<script src="<?= cream_e(str_starts_with((string) $script, '/') ? (string) $script : cream_asset((string) $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
