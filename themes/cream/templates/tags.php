<?php $tags = is_array($tags ?? null) ? $tags : []; ?>
<section class="archive">
  <header class="archive-head">
    <span class="eyebrow">TAGS</span>
    <h1>按标签翻手账</h1>
    <p>同一件小事，写在不同时间里。选一个标签，看看它长成了什么。</p>
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
<?php endif; ?>
</section>
