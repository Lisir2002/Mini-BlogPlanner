<?php
$posts = is_array($posts ?? null) ? $posts : [];
$summary = is_array($summary ?? null) ? $summary : ['total' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0, 'with_changes' => 0];
$allTags = is_array($allTags ?? null) ? $allTags : [];
$status = $status ?? null;
$q = (string) ($q ?? '');
$tag = (string) ($tag ?? '');
$sort = (string) ($sort ?? 'updated');
$changes = (bool) ($changes ?? false);
$total = (int) ($total ?? 0);
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$pendingComments = (int) ($pendingComments ?? 0);
$notice = (string) ($notice ?? '');
$statusUrls = is_array($statusUrls ?? null) ? $statusUrls : [];
$statusKey = is_string($status) && $status !== '' ? $status : 'all';
$hasFilters = is_string($status) || $q !== '' || $tag !== '' || $sort !== 'updated' || $changes;
$activeFilterCount = ($q !== '' ? 1 : 0) + ($tag !== '' ? 1 : 0) + ($sort !== 'updated' ? 1 : 0) + ($changes ? 1 : 0);
$statusTabs = [
    'all' => ['全部', (int) ($summary['total'] ?? 0)],
    'published' => ['已发布', (int) ($summary['published'] ?? 0)],
    'draft' => ['草稿', (int) ($summary['draft'] ?? 0)],
    'archived' => ['已归档', (int) ($summary['archived'] ?? 0)],
];
?>
<section class="admin-page post-admin">
  <div class="post-admin-console">
    <div class="post-admin-rail" role="tablist" aria-label="文章状态">
      <?php foreach ($statusTabs as $key => [$label, $count]): ?>
      <a class="post-admin-rail-tab<?= $statusKey === $key ? ' is-current' : '' ?>" href="<?= cream_e((string) ($statusUrls[$key] ?? '/admin/posts.html')) ?>" role="tab" aria-selected="<?= $statusKey === $key ? 'true' : 'false' ?>">
        <span><?= cream_e($label) ?></span><strong><?= (int) $count ?></strong>
      </a>
      <?php endforeach; ?>
      <span class="post-admin-rail-spacer" aria-hidden="true"></span>
      <?php if ($pendingComments > 0): ?>
      <a class="post-admin-rail-note" href="/admin/comments.html?status=pending">
        <?= cream_icon('heart', 'icon-xs') ?> 待审留言 <strong><?= $pendingComments ?></strong>
      </a>
      <?php endif; ?>
      <a class="admin-button primary post-admin-new" href="/admin/posts/new.html"><?= cream_icon('pen', 'icon-sm') ?> 写新文章</a>
    </div>

    <form class="post-admin-toolbar" method="get" action="/admin/posts.html" role="search" data-admin-filter-panel data-admin-filter-label="筛选文章" data-active-filter-count="<?= $activeFilterCount ?>">
      <label class="post-admin-search">
        <span class="visually-hidden">搜索文章</span>
        <?= cream_icon('search', 'icon-sm') ?>
        <input type="search" name="q" value="<?= cream_e($q) ?>" placeholder="搜索标题或固定链接" autocomplete="off" maxlength="80">
      </label>
      <label class="post-admin-select-field">
        <span class="visually-hidden">按标签筛选</span>
        <select name="tag" aria-label="按标签筛选">
          <option value="">全部标签</option>
          <?php foreach ($allTags as $item): ?>
          <option value="<?= cream_e((string) ($item['slug'] ?? '')) ?>"<?= $tag === (string) ($item['slug'] ?? '') ? ' selected' : '' ?>><?= cream_e((string) ($item['name'] ?? '')) ?>（<?= (int) ($item['count'] ?? 0) ?>）</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="post-admin-select-field">
        <span class="visually-hidden">排序方式</span>
        <select name="sort" aria-label="排序方式">
          <option value="updated"<?= $sort === 'updated' ? ' selected' : '' ?>>最近更新</option>
          <option value="published"<?= $sort === 'published' ? ' selected' : '' ?>>最近发布</option>
          <option value="title"<?= $sort === 'title' ? ' selected' : '' ?>>标题 A-Z</option>
        </select>
      </label>
      <?php if (is_string($status) && $status !== ''): ?><input type="hidden" name="status" value="<?= cream_e($status) ?>"><?php endif; ?>
      <?php if ($changes): ?><input type="hidden" name="changes" value="1"><?php endif; ?>
      <button class="admin-button ghost post-admin-filter-button" type="submit">筛选</button>
      <span class="post-admin-result">筛选后 <strong><?= $total ?></strong> 篇</span>
      <?php if ($hasFilters): ?><a class="post-admin-clear" href="/admin/posts.html">清空</a><?php endif; ?>
      <?php if ((int) ($summary['with_changes'] ?? 0) > 0 && (!is_string($status) || $status === 'published')): ?>
      <a class="post-admin-changes-toggle<?= $changes ? ' is-current' : '' ?>" href="<?= cream_e((string) ($changes ? ($clearChangesUrl ?? '/admin/posts.html') : ($changesUrl ?? '/admin/posts.html?changes=1'))) ?>"<?= $changes ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>>
        <?= cream_icon('pen', 'icon-xs') ?> 未发布改动 <strong><?= (int) $summary['with_changes'] ?></strong>
      </a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($notice !== ''): ?>
  <div class="admin-flash post-admin-flash" role="status"><?= cream_icon('spark', 'icon-sm') ?> <?= cream_e($notice) ?></div>
  <?php endif; ?>

<?php if ($posts === []): ?>
  <div class="admin-empty post-admin-empty">
    <?= cream_icon('book') ?>
    <?php if (!$hasFilters && (int) ($summary['total'] ?? 0) === 0): ?>
    <h2>文章架还是空的</h2>
    <p>写第一篇，发布后它会出现在公开列表和 API。</p>
    <a class="admin-button primary" href="/admin/posts/new.html">写第一篇</a>
    <?php elseif (is_string($status) && $status === 'archived' && $q === '' && $tag === '' && !$changes): ?>
    <h2>归档抽屉还是空的</h2>
    <p>暂时没有收进抽屉的文章。归档的文章会出现在这里。</p>
    <?php else: ?>
    <h2>没有符合条件的文章</h2>
    <p>换个关键词，或清空筛选看看全部。</p>
    <a class="admin-button ghost" href="/admin/posts.html">清空筛选</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php require __DIR__ . '/partials/posts-table.php'; ?>
  <?php require __DIR__ . '/partials/posts-cards.php'; ?>

  <?php if ($pages > 1): ?>
  <nav class="post-admin-pager" aria-label="文章分页">
    <?php if ($prevUrl !== null): ?><a class="admin-button ghost" href="<?= cream_e($prevUrl) ?>">上一页</a><?php else: ?><span class="admin-button ghost is-disabled">上一页</span><?php endif; ?>
    <span>第 <?= $page ?> / <?= $pages ?> 页 · 共 <?= $total ?> 篇</span>
    <?php if ($nextUrl !== null): ?><a class="admin-button ghost" href="<?= cream_e($nextUrl) ?>">下一页</a><?php else: ?><span class="admin-button ghost is-disabled">下一页</span><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</section>
