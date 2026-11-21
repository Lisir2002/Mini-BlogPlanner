<?php
$tags = is_array($tags ?? null) ? $tags : [];
$total = (int) ($total ?? count($tags));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
?>
<section class="archive">
  <header class="archive-head">
    <span class="eyebrow">TAGS</span>
    <p>共 <?= number_format($total) ?> 个标签，每页十个。同一件小事，写在不同时间里。</p>
  </header>

<?php if ($tags === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('tag') ?></span>
    <h2>还没有标签</h2>
    <p>发布文章时加上标签，它们会在这里聚拢。</p>
  </div>
<?php else: ?>
  <div class="tag-cloud">
  <?php foreach ($tags as $tag): ?>
    <a class="tag-card" href="/tags/<?= cream_e($tag['slug']) ?>.html">
      <span class="tag-card-icon"><?= cream_icon('tag') ?></span>
      <strong><?= cream_e($tag['name']) ?></strong>
      <span><?= (int) $tag['count'] ?> 篇</span>
    </a>
  <?php endforeach; ?>
  </div>
  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $total; $pager_prev = $prev_url ?? null; $pager_next = $next_url ?? null; $pager_links = $page_links ?? []; $pager_label = '标签分页'; require __DIR__ . '/partials/page-pager.php'; ?>
<?php endif; ?>
</section>
