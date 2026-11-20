<?php
$site = is_array($site ?? null) ? $site : [];
$title = (string) ($site['title'] ?? '日记Planner');
$description = (string) ($site['description'] ?? '一个安静写字的地方。');
$pageTitle = (string) ($page_title ?? $title);
$locale = (string) ($site['language'] ?? 'zh-CN');
$fullTitle = $pageTitle === $title ? $title : $pageTitle . ' · ' . $title;
$metaDescription = trim((string) ($page_description ?? $description));
$siteUrl = cream_site_url();
$canonicalUrl = trim((string) ($canonical_url ?? ''));
$shareMeta = is_array($share_meta ?? null) ? $share_meta : [];
$shareType = (string) ($shareMeta['type'] ?? 'website');
$shareTitle = (string) ($shareMeta['title'] ?? $pageTitle);
$shareDescription = (string) ($shareMeta['description'] ?? $metaDescription);
$shareUrl = (string) ($shareMeta['url'] ?? $canonicalUrl);
$socialImage = cream_asset('social-card.png');
if ($siteUrl !== '' && !str_starts_with($socialImage, 'http')) {
    $socialImage = $siteUrl . $socialImage;
}
$feedUrl = ($siteUrl === '' ? '' : $siteUrl) . '/feed.xml';
?>
<!doctype html>
<html lang="<?= cream_e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= cream_e($fullTitle) ?></title>
<meta name="description" content="<?= cream_e($metaDescription) ?>">
<?php if ($canonicalUrl !== ''): ?>
<link rel="canonical" href="<?= cream_e($canonicalUrl) ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= cream_e($title) ?> · RSS" href="<?= cream_e($feedUrl) ?>">
<meta property="og:site_name" content="<?= cream_e($title) ?>">
<meta property="og:title" content="<?= cream_e($shareTitle) ?>">
<meta property="og:description" content="<?= cream_e($shareDescription) ?>">
<meta property="og:type" content="<?= cream_e($shareType) ?>">
<?php if ($shareUrl !== ''): ?>
<meta property="og:url" content="<?= cream_e($shareUrl) ?>">
<?php endif; ?>
<?php if (str_starts_with($socialImage, 'http')): ?>
<meta property="og:image" content="<?= cream_e($socialImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= cream_e($title) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= cream_e($shareTitle) ?>">
<meta name="twitter:description" content="<?= cream_e($shareDescription) ?>">
<?php if (str_starts_with($socialImage, 'http')): ?>
<meta name="twitter:image" content="<?= cream_e($socialImage) ?>">
<?php endif; ?>
<?php if ($shareType === 'article'): ?>
<meta property="article:published_time" content="<?= cream_e((string) ($shareMeta['published'] ?? '')) ?>">
<meta property="article:modified_time" content="<?= cream_e((string) ($shareMeta['modified'] ?? '')) ?>">
<?php foreach (($shareMeta['tags'] ?? []) as $shareTag): ?>
<meta property="article:tag" content="<?= cream_e((string) $shareTag) ?>">
<?php endforeach; ?>
<?php endif; ?>
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
