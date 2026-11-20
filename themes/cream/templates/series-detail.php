<?php
$series = is_array($series ?? null) ? $series : ['name' => '', 'slug' => '', 'description' => '', 'count' => 0];
$posts = is_array($posts ?? null) ? $posts : [];
$count = count($posts);
?>
<section class="series-detail-page">
  <header class="series-hero">
    <a class="back-link" href="/series.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 所有系列</a>
    <span class="eyebrow">SERIES</span>
    <h1><?= cream_e($series['name']) ?></h1>
    <p><?= cream_e($series['description'] !== '' ? $series['description'] : '这条线里的文章，按发布时间从早到晚排列。') ?></p>
    <div class="series-hero-meta">
      <span><?= $count ?> 篇</span>
      <span aria-hidden="true">·</span>
      <span>从早到晚</span>
    </div>
  </header>

<?php if ($posts === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('layers') ?></span>
    <h2>这条线还没有文章</h2>
    <p>把已发布的文章归入这个系列，它们会按时间排在这里。</p>
  </div>
<?php else: ?>
  <div class="series-timeline">
  <?php foreach ($posts as $index => $post): ?>
    <a class="series-step" href="/posts/<?= cream_e($post->slug) ?>.html">
      <span class="series-step-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
      <div class="series-step-main">
        <div class="series-step-meta">
          <time datetime="<?= cream_e($post->publishedAt) ?>"><?= cream_e(cream_date($post->publishedAt)) ?></time>
          <span aria-hidden="true">·</span>
          <span><?= (int) $post->readingMinutes ?> 分钟</span>
        </div>
        <h2><?= cream_e($post->title) ?></h2>
        <?php if ($post->excerpt !== ''): ?><p><?= cream_e($post->excerpt) ?></p><?php endif; ?>
      </div>
      <span class="series-step-arrow"><?= cream_icon('arrow-right', 'icon-sm') ?></span>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
