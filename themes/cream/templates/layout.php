<?php
$site = is_array($site ?? null) ? $site : [];
$title = (string) ($site['title'] ?? '日记Planner');
$description = (string) ($site['description'] ?? '一个安静写字的地方。');
$pageTitle = (string) ($page_title ?? $title);
$locale = (string) ($site['language'] ?? 'zh-CN');
$fullTitle = $pageTitle === $title ? $title : $pageTitle . ' · ' . $title;
?>
<!doctype html>
<html lang="<?= cream_e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= cream_e($fullTitle) ?></title>
<meta name="description" content="<?= cream_e($description) ?>">
<?php require __DIR__ . '/partials/loader-style.php'; ?>
<link rel="preload" href="<?= cream_asset('icons.svg') ?>" as="image" type="image/svg+xml" fetchpriority="low">
<link rel="stylesheet" href="<?= cream_asset('cream.css') ?>" fetchpriority="high">
<link rel="icon" href="<?= cream_asset('favicon.svg') ?>" type="image/svg+xml">
<?php foreach (($page_styles ?? []) as $style): ?>
<link rel="stylesheet" href="<?= cream_e(str_starts_with((string) $style, '/') ? (string) $style : cream_asset((string) $style)) ?>" fetchpriority="high">
<?php endforeach; ?>
</head>
<body>
<?php require __DIR__ . '/partials/loader.php'; ?>
<a class="skip-link" href="#main">跳到正文</a>
<?php require __DIR__ . '/partials/site-header.php'; ?>
<main id="main" class="site-main">
<?= $content ?>
</main>
<?php require __DIR__ . '/partials/site-footer.php'; ?>
<script src="<?= cream_asset('cream.js') ?>" defer onerror="document.getElementById('cream-loader').hidden=true"></script>
<?php foreach (($page_scripts ?? []) as $script): ?>
<script src="<?= cream_e(str_starts_with((string) $script, '/') ? (string) $script : cream_asset((string) $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
