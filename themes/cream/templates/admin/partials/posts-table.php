<div class="post-admin-bulk-scope" data-post-bulk-scope="desktop">
  <div class="post-admin-table-wrap">
    <table class="admin-table post-admin-table">
      <caption class="visually-hidden">文章列表</caption>
      <thead>
        <tr>
          <th class="post-admin-col-check"><input type="checkbox" data-post-select-all aria-label="全选当前页文章"></th>
          <th scope="col">文章</th>
          <th scope="col">状态</th>
          <th scope="col">标签</th>
          <th scope="col">回声</th>
          <th scope="col">更新</th>
          <th scope="col" class="post-admin-col-actions">操作</th>
        </tr>
      </thead>
      <tbody>
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
        <tr data-post-id="<?= $postId ?>" data-post-status="<?= cream_e($postStatus) ?>">
          <td class="post-admin-col-check"><input type="checkbox" form="post-bulk-desktop" name="ids[]" value="<?= $postId ?>" data-post-row-checkbox aria-label="选择文章：<?= cream_e($postTitle) ?>"></td>
          <td class="post-admin-title-cell">
            <a class="post-admin-title-link" href="/admin/posts/<?= $postId ?>.html"><?= cream_e($postTitle) ?></a>
            <span class="post-admin-slug">/<?= cream_e($postSlug) ?>.html</span>
            <?php if ($hasChanges): ?><span class="post-admin-change-chip">草稿待发布</span><?php endif; ?>
          </td>
          <td class="post-admin-status-cell">
            <span class="status-badge status-<?= cream_e($postStatus) ?>"><?= cream_e(match ($postStatus) { 'published' => '已发布', 'archived' => '已归档', default => '草稿' }) ?></span>
            <span class="post-admin-status-meta">
              <?php if ($postStatus === 'published' && $publishedShort !== ''): ?>发布于 <?= cream_e($publishedShort) ?>
              <?php elseif ($postStatus === 'archived'): ?>已收进归档
              <?php else: ?>尚未发布
              <?php endif; ?>
            </span>
          </td>
          <td class="post-admin-tags-cell">
            <?php if ($tags === []): ?>
            <span class="post-admin-muted">—</span>
            <?php else: ?>
            <span class="post-admin-tags">
              <?php foreach (array_slice($tags, 0, 2) as $item): ?><span class="post-admin-tag"><?= cream_e((string) ($item['name'] ?? '')) ?></span><?php endforeach; ?>
              <?php if ($tagExtra > 0): ?><span class="post-admin-tag-more">+<?= $tagExtra ?></span><?php endif; ?>
            </span>
            <?php endif; ?>
          </td>
          <td class="post-admin-echo-cell">
            <span class="post-admin-echo-line">留言 <strong><?= $approved ?></strong><?php if ($pending > 0): ?> · <a class="is-pending" href="/admin/comments.html?status=pending">待审 <?= $pending ?></a><?php endif; ?><?php if ($spam > 0): ?> · 垃圾 <?= $spam ?><?php endif; ?></span>
            <span class="post-admin-echo-line post-admin-muted">赞 <?= (int) ($post['reaction_likes'] ?? 0) ?> · 踩 <?= (int) ($post['reaction_dislikes'] ?? 0) ?></span>
          </td>
          <td class="post-admin-updated-cell"><time class="admin-mono" datetime="<?= cream_e($updatedRaw) ?>" title="<?= cream_e($updatedRaw) ?>"><?= cream_e($updatedShort) ?></time></td>
          <td class="post-admin-actions-cell">
            <?php require __DIR__ . '/post-row-actions.php'; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<form id="post-bulk-desktop" class="post-admin-bulk-form post-admin-bulk-form--desktop" method="post" action="/admin/posts/bulk.html" data-post-bulk-scope="desktop">
  <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
  <input type="hidden" name="bulk_action" value="">
  <input type="hidden" name="return_query" value="<?= cream_e((string) ($returnQuery ?? '')) ?>">
  <?php require __DIR__ . '/posts-bulk-bar.php'; ?>
</form>
