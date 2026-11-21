<div class="post-admin-bulk-scope post-admin-cards-scope" data-post-bulk-scope="mobile">
  <div class="post-admin-cards-head">
    <label><input type="checkbox" data-post-select-all aria-label="全选当前页文章"> 全选本页</label>
    <span>共 <?= count($posts) ?> 篇</span>
  </div>
  <ul class="post-admin-cards">
    <?php foreach ($posts as $post): ?>
    <?php
    $postId = (int) ($post['id'] ?? 0);
    $postStatus = (string) ($post['status'] ?? 'draft');
    $postTitle = (string) ($post['title'] ?? '');
    $postSlug = (string) ($post['slug'] ?? '');
    $updatedRaw = (string) ($post['updated_at'] ?? '');
    $updatedShort = $updatedRaw !== '' ? substr($updatedRaw, 5, 11) : '—';
    $publishedRaw = (string) ($post['published_at'] ?? '');
    $publishedShort = $publishedRaw !== '' ? substr($publishedRaw, 5, 11) : '';
    $tags = is_array($post['tags'] ?? null) ? $post['tags'] : [];
    $tagExtra = max(0, count($tags) - 2);
    $approved = (int) ($post['comment_approved'] ?? 0);
    $pending = (int) ($post['comment_pending'] ?? 0);
    $spam = (int) ($post['comment_spam'] ?? 0);
    $hasChanges = (bool) ($post['has_changes'] ?? false);
    ?>
    <li class="post-admin-card" data-post-id="<?= $postId ?>" data-post-status="<?= cream_e($postStatus) ?>">
      <div class="post-admin-card-top">
        <input type="checkbox" form="post-bulk-mobile" name="ids[]" value="<?= $postId ?>" data-post-row-checkbox aria-label="选择文章：<?= cream_e($postTitle) ?>">
        <a class="post-admin-card-title" href="/admin/posts/<?= $postId ?>.html"><?= cream_e($postTitle) ?></a>
        <span class="status-badge status-<?= cream_e($postStatus) ?>"><?= cream_e(match ($postStatus) { 'published' => '已发布', 'archived' => '已归档', default => '草稿' }) ?></span>
      </div>
      <span class="post-admin-card-slug">/<?= cream_e($postSlug) ?>.html</span>
      <?php if ($hasChanges): ?><span class="post-admin-change-chip">草稿待发布</span><?php endif; ?>
      <div class="post-admin-card-meta">
        <span><?php if ($postStatus === 'published' && $publishedShort !== ''): ?>发布于 <?= cream_e($publishedShort) ?><?php elseif ($postStatus === 'archived'): ?>已收进归档<?php else: ?>更新于 <?= cream_e($updatedShort) ?><?php endif; ?></span>
        <?php if ($tags !== []): ?>
        <span class="post-admin-tags">
          <?php foreach (array_slice($tags, 0, 2) as $item): ?><span class="post-admin-tag"><?= cream_e((string) ($item['name'] ?? '')) ?></span><?php endforeach; ?>
          <?php if ($tagExtra > 0): ?><span class="post-admin-tag-more">+<?= $tagExtra ?></span><?php endif; ?>
        </span>
        <?php endif; ?>
      </div>
      <div class="post-admin-card-meta post-admin-card-echo">
        <span>留言 <?= $approved ?><?php if ($pending > 0): ?> · <a class="is-pending" href="/admin/comments.html?status=pending">待审 <?= $pending ?></a><?php endif; ?><?php if ($spam > 0): ?> · 垃圾 <?= $spam ?><?php endif; ?></span>
        <span class="post-admin-muted">赞 <?= (int) ($post['reaction_likes'] ?? 0) ?> · 踩 <?= (int) ($post['reaction_dislikes'] ?? 0) ?></span>
      </div>
      <div class="post-admin-card-actions">
        <?php require __DIR__ . '/post-row-actions.php'; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<form id="post-bulk-mobile" class="post-admin-bulk-form post-admin-bulk-form--mobile" method="post" action="/admin/posts/bulk.html" data-post-bulk-scope="mobile">
  <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
  <input type="hidden" name="bulk_action" value="">
  <input type="hidden" name="return_query" value="<?= cream_e((string) ($returnQuery ?? '')) ?>">
  <?php require __DIR__ . '/posts-bulk-bar.php'; ?>
</form>
