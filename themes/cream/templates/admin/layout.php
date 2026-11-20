<?php
$admin = $admin ?? null;
$title = (string) ($title ?? '后台');
$csrf = (string) ($csrf ?? '');
$current = (string) ($current ?? '');
$siteTitle = (string) (($site['title'] ?? '日记Planner'));
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
    <a class="admin-brand" href="/admin/posts.html">
      <span class="admin-brand-mark"><?= cream_icon('planner') ?></span>
      <span class="admin-brand-copy"><small>MINI-BLOGPLANNER</small><strong><?= cream_e($siteTitle) ?> 后台</strong></span>
    </a>
    <?php if ($admin !== null): ?>
    <nav class="admin-nav nav-desktop" aria-label="后台导航">
      <a class="<?= $current === 'posts' ? 'is-current' : '' ?>" href="/admin/posts.html">文章</a>
      <a class="<?= $current === 'tags' ? 'is-current' : '' ?>" href="/admin/tags.html">标签</a>
      <a class="<?= $current === 'comments' ? 'is-current' : '' ?>" href="/admin/comments.html">评论</a>
      <a class="<?= $current === 'media' ? 'is-current' : '' ?>" href="/admin/media.html">媒体</a>
      <a class="<?= $current === 'audit' ? 'is-current' : '' ?>" href="/admin/audit.html">审计</a>
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
            <a class="nav-drawer-link<?= $current === 'posts' ? ' is-current' : '' ?>" href="/admin/posts.html"<?= $current === 'posts' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('book', 'icon-sm') ?></span>
              <span class="nav-drawer-text">文章</span>
              <span class="nav-drawer-index">01</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'tags' ? ' is-current' : '' ?>" href="/admin/tags.html"<?= $current === 'tags' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('tag', 'icon-sm') ?></span>
              <span class="nav-drawer-text">标签</span>
              <span class="nav-drawer-index">02</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'comments' ? ' is-current' : '' ?>" href="/admin/comments.html"<?= $current === 'comments' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('comment', 'icon-sm') ?></span>
              <span class="nav-drawer-text">评论</span>
              <span class="nav-drawer-index">03</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'media' ? ' is-current' : '' ?>" href="/admin/media.html"<?= $current === 'media' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('layers', 'icon-sm') ?></span>
              <span class="nav-drawer-text">媒体</span>
              <span class="nav-drawer-index">04</span>
            </a>
            <a class="nav-drawer-link<?= $current === 'audit' ? ' is-current' : '' ?>" href="/admin/audit.html"<?= $current === 'audit' ? ' aria-current="page"' : '' ?>>
              <span class="nav-drawer-icon"><?= cream_icon('shield', 'icon-sm') ?></span>
              <span class="nav-drawer-text">审计</span>
              <span class="nav-drawer-index">05</span>
            </a>
            <a class="nav-drawer-link" href="/" target="_blank" rel="noopener">
              <span class="nav-drawer-icon"><?= cream_icon('external', 'icon-sm') ?></span>
              <span class="nav-drawer-text">查看站点</span>
              <span class="nav-drawer-index">06</span>
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
    <?php endif; ?>
  </div>
</header>
<main id="admin-main" class="admin-main">
<?= $content ?>
</main>
<script src="<?= cream_asset('cream.js') ?>" defer onerror="document.getElementById('cream-loader').hidden=true"></script>
<?php foreach (($page_scripts ?? []) as $script): ?>
<script src="<?= cream_e(str_starts_with((string) $script, '/') ? (string) $script : cream_asset((string) $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
