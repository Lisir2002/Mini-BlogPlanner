<?php
$posts = is_array($posts ?? null) ? $posts : [];
?>
<section class="archive">
  <header class="archive-head">
    <span class="eyebrow">ARCHIVE</span>
    <h1>所有写过的东西</h1>
    <p>按时间倒序。没有推荐算法，只有一页一页翻。</p>
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
      <div class="post-meta">
        <time datetime="<?= cream_e($post->publishedAt) ?>"><?= cream_e(cream_date($post->publishedAt)) ?></time>
        <span><?= (int) $post->readingMinutes ?> 分钟</span>
      </div>
      <h2><a href="/posts/<?= cream_e($post->slug) ?>.html"><?= cream_e($post->title) ?></a></h2>
      <p><?= cream_e($post->excerpt) ?></p>
      <a class="text-link" href="/posts/<?= cream_e($post->slug) ?>.html">继续读 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </article>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
