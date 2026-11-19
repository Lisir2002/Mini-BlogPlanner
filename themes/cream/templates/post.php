<?php
$post = $post ?? null;
if (!is_object($post)) {
    return;
}
?>
<article class="post-page">
  <span class="tape tape-butter article-tape" aria-hidden="true"></span>
  <?php if (($preview ?? false) === true): ?>
  <div class="preview-note">预览模式：这篇文章尚未发布，搜索引擎不会收录。</div>
  <?php endif; ?>
  <header class="post-head">
    <a class="back-link" href="/posts.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 回到文章列表</a>
    <div class="post-meta">
      <time datetime="<?= cream_e($post->publishedAt) ?>"><?= cream_e(cream_date($post->publishedAt)) ?></time>
      <span><?= (int) $post->readingMinutes ?> 分钟</span>
    </div>
    <h1><?= cream_e($post->title) ?></h1>
    <?php if ($post->excerpt !== ''): ?><p class="post-lead"><?= cream_e($post->excerpt) ?></p><?php endif; ?>
    <?php if ($post->tags !== []): ?>
      <div class="post-tags">
      <?php foreach ($post->tags as $tag): ?>
        <span class="tag-pill"><?= cream_icon('tag', 'icon-xs') ?> <?= cream_e($tag) ?></span>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </header>
  <div class="post-body">
<?= $post->body ?>
  </div>
</article>

<?php require __DIR__ . '/partials/comments.php'; ?>
