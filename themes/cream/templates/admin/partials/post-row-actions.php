<?php
$post = is_array($post ?? null) ? $post : [];
$postId = (int) ($post['id'] ?? 0);
$postTitle = (string) ($post['title'] ?? '');
$postStatus = (string) ($post['status'] ?? 'draft');
$postSlug = (string) ($post['slug'] ?? '');
?>
<div class="post-admin-row-actions">
  <a class="post-admin-edit" href="/admin/posts/<?= $postId ?>.html">编辑</a>
  <button class="post-admin-menu-trigger" type="button" data-post-menu aria-haspopup="menu" aria-expanded="false" aria-label="更多操作：<?= cream_e($postTitle) ?>">
    <span aria-hidden="true">⋯</span>
  </button>
</div>
<template class="post-admin-menu-template">
  <div class="post-admin-menu" role="menu" aria-label="文章操作">
    <a class="post-admin-menu-item" role="menuitem" href="/admin/posts/<?= $postId ?>/preview.html" target="_blank" rel="noopener">预览草稿<span>新窗口</span></a>
    <?php if ($postStatus === 'published'): ?>
    <a class="post-admin-menu-item" role="menuitem" href="/posts/<?= cream_e($postSlug) ?>.html" target="_blank" rel="noopener">查看公开页<span>新窗口</span></a>
    <?php endif; ?>
    <a class="post-admin-menu-item" role="menuitem" href="/admin/posts/<?= $postId ?>/revisions.html">版本历史<span>已发布快照</span></a>
    <?php if ($postStatus === 'archived'): ?>
    <form method="post" action="/admin/posts/<?= $postId ?>/restore.html" class="post-admin-menu-form">
      <input type="hidden" name="_csrf" value="<?= cream_e((string) ($csrf ?? '')) ?>">
      <input type="hidden" name="return_query" value="<?= cream_e((string) ($returnQuery ?? '')) ?>">
      <button class="post-admin-menu-item" role="menuitem" type="submit">恢复为草稿<span>不会自动发布</span></button>
    </form>
    <?php else: ?>
    <form method="post" action="/admin/posts/<?= $postId ?>/archive.html" class="post-admin-menu-form" data-confirm-title="把这篇文章收进归档？" data-confirm-body="归档后公开页和 API 不再显示。文章数据会保留，可随时恢复为草稿。">
      <input type="hidden" name="_csrf" value="<?= cream_e((string) ($csrf ?? '')) ?>">
      <input type="hidden" name="return_query" value="<?= cream_e((string) ($returnQuery ?? '')) ?>">
      <button class="post-admin-menu-item is-danger" role="menuitem" type="submit">收进归档<span>公开页不再显示</span></button>
    </form>
    <?php endif; ?>
  </div>
</template>
