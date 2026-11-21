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
  <nav class="archive-pagination" aria-label="归档分页">
<?php if ($prevUrl !== null): ?>
    <a class="archive-page-link" href="<?= cream_e($prevUrl) ?>"><?= cream_icon('arrow-left', 'icon-xs') ?> 上一页</a>
<?php else: ?>
    <span class="archive-page-link is-disabled"><?= cream_icon('arrow-left', 'icon-xs') ?> 上一页</span>
<?php endif; ?>
    <span class="archive-page-count">第 <?= $page ?> / <?= $pages ?> 页</span>
<?php if ($page > 1 && $monthUrl !== ''): ?>
    <a class="archive-page-link archive-page-first" href="<?= cream_e($monthUrl) ?>">本月第一页</a>
<?php endif; ?>
<?php if ($nextUrl !== null): ?>
    <a class="archive-page-link" href="<?= cream_e($nextUrl) ?>">下一页 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
<?php else: ?>
    <span class="archive-page-link is-disabled">下一页 <?= cream_icon('arrow-right', 'icon-xs') ?></span>
<?php endif; ?>
  </nav>
<?php endif; ?>
</section>
