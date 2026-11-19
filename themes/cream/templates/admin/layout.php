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
<link rel="icon" href="<?= cream_asset('favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="admin-body">
<?php require dirname(__DIR__) . '/partials/loader.php'; ?>
<a class="skip-link" href="#admin-main">跳到正文</a>
<header class="admin-header">
  <div class="admin-header-inner">
    <a class="admin-brand" href="/admin/posts.html">
      <span class="admin-brand-mark"><?= cream_icon('planner') ?></span>
      <span class="admin-brand-copy"><small>MINI-BLOGPLANNER</small><strong><?= cream_e($siteTitle) ?> 后台</strong></span>
    </a>
    <?php if ($admin !== null): ?>
    <nav class="admin-nav" aria-label="后台导航">
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
    <?php endif; ?>
  </div>
</header>
<main id="admin-main" class="admin-main">
<?= $content ?>
</main>
<script src="<?= cream_asset('cream.js') ?>" defer onerror="document.getElementById('cream-loader').hidden=true"></script>
</body>
</html>
