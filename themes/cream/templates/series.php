<?php
$series = is_array($series ?? null) ? $series : [];
?>
<section class="series-index">
  <header class="archive-head series-head">
    <span class="eyebrow">SERIES</span>
    <h1>成串地读</h1>
    <p>把同一段旅程里的文章串起来。从第一篇开始，按时间往下翻。</p>
  </header>

<?php if ($series === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('layers') ?></span>
    <h2>还没有系列</h2>
    <p>在后台把文章归入系列，它们会在这里排成一条线。</p>
  </div>
<?php else: ?>
  <div class="series-grid">
  <?php foreach ($series as $item): ?>
    <a class="series-card" href="/series/<?= cream_e($item['slug']) ?>.html">
      <span class="series-card-spine" aria-hidden="true"></span>
      <span class="series-card-kicker">SERIES</span>
      <strong><?= cream_e($item['name']) ?></strong>
      <p><?= cream_e($item['description'] !== '' ? $item['description'] : '这条线还在继续写。') ?></p>
      <span class="series-card-foot">
        <span><?= (int) $item['count'] ?> 篇</span>
        <span class="series-card-arrow"><?= cream_icon('arrow-right', 'icon-xs') ?></span>
      </span>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
