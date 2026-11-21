<?php
$year = (int) ($year ?? 0);
$yearCount = (int) ($year_count ?? 0);
$months = is_array($months ?? null) ? $months : [];
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
?>
<section class="archive archive-year">
  <header class="archive-head">
    <a class="back-link" href="/archive.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 全部归档</a>
    <span class="eyebrow">YEAR</span>
    <p>这一年共 <?= number_format($yearCount) ?> 篇，每页十条，按月份排下去。</p>
  </header>

<?php if ($months === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('calendar') ?></span>
    <h2>这一年还没有文章</h2>
    <p>换一年看看，或者回到 <a href="/archive.html">归档总览</a>。</p>
  </div>
<?php else: ?>
  <div class="archive-year-timeline">
<?php foreach ($months as $month): ?>
    <section class="archive-month-block">
      <header class="archive-month-head">
        <h2><a href="/archive/<?= $year ?>/<?= str_pad((string) (int) $month['month'], 2, '0', STR_PAD_LEFT) ?>.html"><?= (int) $month['month'] ?> 月</a></h2>
        <span><?= number_format((int) $month['count']) ?> 篇</span>
      </header>
      <div class="archive-line-list">
<?php foreach ($month['posts'] as $post): ?>
        <a class="archive-line" href="/posts/<?= cream_e($post->slug) ?>.html">
          <time datetime="<?= cream_e($post->publishedAt) ?>"><?= cream_e(substr(cream_date($post->publishedAt), 5)) ?></time>
          <span class="archive-line-title"><?= cream_e($post->title) ?></span>
          <span class="archive-line-arrow"><?= cream_icon('arrow-right', 'icon-xs') ?></span>
        </a>
<?php endforeach; ?>
      </div>
    </section>
<?php endforeach; ?>
  </div>
  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $yearCount; $pager_prev = $prev_url ?? null; $pager_next = $next_url ?? null; $pager_links = $page_links ?? []; $pager_label = $year . ' 年归档分页'; require __DIR__ . '/partials/page-pager.php'; ?>
<?php endif; ?>
</section>
