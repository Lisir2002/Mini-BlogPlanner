<?php
$posts = is_array($posts ?? null) ? $posts : [];
$total = (int) ($total ?? count($posts));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
?>
<section class="archive">
  <header class="archive-head">
    <span class="eyebrow">ARCHIVE</span>
    <p>按时间倒序，每页十条。共 <?= number_format($total) ?> 篇，没有推荐算法，只有一页一页翻。</p>
  </header>

<?php if ($posts === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('pen') ?></span>
    <h2>还没有文章</h2>
    <p>第一篇正在路上。先去 <a href="/">首页</a> 看看这本手账。</p>
  </div>
<?php else: ?>
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
  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $total; $pager_prev = $prev_url ?? null; $pager_next = $next_url ?? null; $pager_links = $page_links ?? []; $pager_label = '文章分页'; require __DIR__ . '/partials/page-pager.php'; ?>
<?php endif; ?>
</section>
