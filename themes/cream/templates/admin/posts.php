<?php $posts = is_array($posts ?? null) ? $posts : []; ?>
<section class="admin-page">
  <header class="admin-page-head">
    <div>
      <span class="eyebrow">POSTS</span>
      <h1>文章管理</h1>
      <p>草稿、已发布和已归档都在这里。发布后才会出现在公开列表和 API。</p>
    </div>
    <a class="admin-button primary" href="/admin/posts/new.html"><?= cream_icon('pen') ?> 写新文章</a>
  </header>

<?php if ($posts === []): ?>
  <div class="admin-empty">
    <?= cream_icon('book') ?>
    <h2>还没有文章</h2>
    <p>写第一篇，发布后它会出现在 <a href="/posts.html">/posts.html</a>。</p>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>标题</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($posts as $post): ?>
        <tr>
          <td><strong><?= cream_e($post['title'] ?? '') ?></strong><span class="admin-slug">/<?= cream_e($post['slug'] ?? '') ?>.html</span></td>
          <td><span class="status-badge status-<?= cream_e($post['status'] ?? 'draft') ?>"><?= cream_e(match ((string) ($post['status'] ?? 'draft')) { 'published' => '已发布', 'archived' => '已归档', default => '草稿' }) ?></span></td>
          <td class="admin-mono"><?= cream_e((string) ($post['updated_at'] ?? '')) ?></td>
          <td class="admin-actions">
            <a href="/admin/posts/<?= (int) ($post['id'] ?? 0) ?>.html">编辑</a>
            <a href="/admin/posts/<?= (int) ($post['id'] ?? 0) ?>/preview.html" target="_blank" rel="noopener">预览</a>
            <?php if (($post['status'] ?? '') === 'published'): ?>
            <a href="/posts/<?= cream_e($post['slug'] ?? '') ?>.html" target="_blank" rel="noopener">查看</a>
            <?php endif; ?>
            <form method="post" action="/admin/posts/<?= (int) ($post['id'] ?? 0) ?>/archive.html" class="inline-form" onsubmit="return confirm('确认归档这篇文章？')">
              <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
              <button class="link-button" type="submit">归档</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
