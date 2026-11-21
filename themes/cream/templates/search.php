<?php
$query = is_string($query ?? null) ? $query : '';
$items = is_array($items ?? null) ? $items : [];
?>
<section class="archive search-page">
  <header class="archive-head search-head">
    <span class="eyebrow">SEARCH</span>
    <p>搜标题、摘要和正文里的词，找到写过的那一页。</p>
    <form class="search-form" action="/search" method="get" role="search">
      <label class="sr-only" for="search-q">搜索文章</label>
      <span class="search-field-icon" aria-hidden="true"><?= cream_icon('search', 'icon-sm') ?></span>
      <input id="search-q" type="search" name="q" value="<?= cream_e($query) ?>" placeholder="试试：手账 / 慢一点 / 建站" maxlength="60" autocomplete="off">
      <button class="search-submit" type="submit">搜索</button>
    </form>
  </header>

<?php if ($query === ''): ?>
  <div class="empty-note search-empty">
    <span class="empty-icon"><?= cream_icon('search') ?></span>
    <h2>从一两个词开始</h2>
    <p>中文搜索按两字词匹配；只输入一个字时会用更宽松的包含查找。</p>
  </div>
<?php elseif ($items === []): ?>
  <div class="empty-note search-empty">
    <span class="empty-icon"><?= cream_icon('search') ?></span>
    <h2>没有翻到这一页</h2>
    <p>换个更短的关键词试试，或去 <a href="/tags.html">标签页</a> 按主题找。</p>
  </div>
<?php else: ?>
  <p class="search-summary">找到 <strong><?= count($items) ?></strong> 篇与「<span><?= cream_e($query) ?></span>」有关的文章。</p>
  <div class="post-list search-results">
<?php foreach ($items as $item): ?>
    <?php $post = $item['post']; ?>
    <article class="post-card search-card">
      <?php $compact = true; $show_updated = false; require __DIR__ . '/partials/post-meta.php'; ?>
      <h2><a href="/posts/<?= cream_e($post->slug) ?>.html"><?= cream_e($post->title) ?></a></h2>
      <p><?= cream_e((string) ($item['snippet'] ?? $post->excerpt)) ?></p>
      <a class="text-link" href="/posts/<?= cream_e($post->slug) ?>.html">继续读 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </article>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
