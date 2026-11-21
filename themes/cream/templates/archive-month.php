<?php
$year = (int) ($year ?? 0);
$month = (int) ($month ?? 0);
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$total = (int) ($total ?? 0);
$posts = is_array($posts ?? null) ? $posts : [];
$monthUrl = (string) ($month_url ?? '');
$prevUrl = is_string($prev_url ?? null) ? $prev_url : null;
$nextUrl = is_string($next_url ?? null) ? $next_url : null;
?>
<section class="archive archive-month">
  <header class="archive-head">
    <a class="back-link" href="/archive/<?= $year ?>.html"><?= cream_icon('arrow-left', 'icon-xs') ?> <?= $year ?> 年</a>
    <span class="eyebrow">MONTH</span>
    <p>这个月共 <?= number_format($total) ?> 篇，按发布时间倒序。</p>
  </header>

  <div class="post-list">
<?php foreach ($posts as $post): ?>
    <article class="post-card">
      <?php $compact = true; $show_updated = false; require __DIR__ . '/partials/post-meta.php'; ?>
      <h2><a href="/posts/<?= cream_e($post->slug) ?>.html"><?= cream_e($post->title) ?></a></h2>
      <p><?= cream_e($post->excerpt) ?></p>
      <a class="text-link" href="/posts/<?= cream_e($post->slug) ?>.html">继续读 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </article>
<?php endforeach; ?>
  </div>

<?php if ($pages > 1): ?>
  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $total; $pager_prev = $prevUrl; $pager_next = $nextUrl; $pager_links = $page_links ?? []; $pager_label = '归档分页'; require __DIR__ . '/partials/page-pager.php'; ?>
<?php endif; ?>
</section>
