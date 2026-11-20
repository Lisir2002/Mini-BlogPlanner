<?php
$post = $post ?? null;
if (!is_object($post)) {
    return;
}
$series = is_array($series ?? null) ? $series : null;
?>
<article class="post-page">
  <span class="tape tape-butter article-tape" aria-hidden="true"></span>
  <?php if (($preview ?? false) === true): ?>
  <div class="preview-note">预览模式：这篇文章尚未发布，搜索引擎不会收录。</div>
  <?php endif; ?>
  <header class="post-head">
    <a class="back-link" href="/posts.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 回到文章列表</a>
    <?php require __DIR__ . '/partials/post-meta.php'; ?>
    <h1><?= cream_e($post->title) ?></h1>
    <?php if ($post->excerpt !== ''): ?>
      <aside class="post-lead" aria-label="文章概览">
        <span class="post-lead-tape" aria-hidden="true"></span>
        <div class="post-lead-paper">
          <span class="post-lead-label"><?= cream_icon('spark', 'icon-xs') ?> 文章概览</span>
          <p class="post-lead-text"><?= cream_e($post->excerpt) ?></p>
        </div>
      </aside>
    <?php endif; ?>
    <?php if ($post->tags !== []): ?>
      <div class="post-tags">
      <?php foreach ($post->tags as $tag): ?>
        <span class="tag-pill"><?= cream_icon('tag', 'icon-xs') ?> <?= cream_e($tag) ?></span>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($series !== null): ?>
      <a class="post-series-link" href="/series/<?= cream_e($series['slug']) ?>.html">
        <span class="post-series-kicker">SERIES</span>
        <strong><?= cream_e($series['name']) ?></strong>
        <span>查看系列 <?= cream_icon('arrow-right', 'icon-xs') ?></span>
      </a>
    <?php endif; ?>
  </header>
  <div class="post-body">
<?= $post->body ?>
  </div>
  <?php if (($preview ?? false) !== true): ?>
  <?php require __DIR__ . '/partials/post-receipt.php'; ?>
  <?php endif; ?>
</article>

<?php require __DIR__ . '/partials/comments.php'; ?>
