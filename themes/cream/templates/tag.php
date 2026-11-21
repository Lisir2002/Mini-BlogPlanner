<?php
$tag = is_array($tag ?? null) ? $tag : ['name' => '', 'slug' => ''];
$posts = is_array($posts ?? null) ? $posts : [];
$total = (int) ($total ?? count($posts));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
?>
<section class="archive">
  <header class="archive-head">
    <a class="back-link" href="/tags.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 所有标签</a>
    <span class="eyebrow">TAG</span>
    <p>这个标签下共有 <?= number_format($total) ?> 篇已发布文章，每页十条。</p>
  </header>

<?php if ($posts === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('tag') ?></span>
    <h2>暂时没有文章</h2>
    <p>这个标签还在等第一篇文字。</p>
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
  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $total; $pager_prev = $prev_url ?? null; $pager_next = $next_url ?? null; $pager_links = $page_links ?? []; $pager_label = '标签文章分页'; require __DIR__ . '/partials/page-pager.php'; ?>
<?php endif; ?>
</section>
