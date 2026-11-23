<?php
$tags = is_array($tags ?? null) ? $tags : [];
$tagOptions = is_array($tagOptions ?? null) ? $tagOptions : $tags;
$summary = is_array($summary ?? null) ? $summary : [];
$filters = is_array($filters ?? null) ? $filters : [];
$total = (int) ($total ?? count($tags));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$ok = is_string($ok ?? null) ? $ok : null;
$err = is_string($err ?? null) ? $err : null;
$cleaned = (int) ($cleaned ?? 0);
$q = (string) ($filters['q'] ?? '');
$status = (string) ($filters['status'] ?? 'all');
$sort = (string) ($filters['sort'] ?? 'usage');
$okMessages = [
    'created' => '标签已创建，可以从文章编辑器里开始使用。',
    'renamed' => '标签已重命名，原有 slug 和文章关联保持不变。',
    'merged' => '标签已合并，文章关联已经转到目标标签。',
    'deleted' => '空标签已删除。',
    'cleaned' => $cleaned > 0 ? '已清理 ' . $cleaned . ' 个空标签。' : '没有需要清理的空标签。',
];
$errMessages = [
    'invalid' => '标签名称不能为空，也不能超过 60 个字。',
    'duplicate' => '已经存在同名标签，请换一个名称或直接合并。',
    'merge' => '合并目标无效，请选择一个不同的标签。',
    'notempty' => '有文章关联的标签不能直接删除，请先合并到其他标签。',
    'notfound' => '标签不存在或已经被删除。',
];
$maxCount = 1;
foreach ($tags as $tag) {
    $maxCount = max($maxCount, (int) $tag['count']);
}
$accent = static fn (string $slug): string => ['butter', 'mint', 'coral', 'sky', 'rose'][abs((int) crc32($slug)) % 5];
$statusLabels = ['all' => '全部', 'used' => '有文章', 'empty' => '待整理'];
$sortLabels = ['usage' => '按使用量', 'name' => '按名称', 'recent' => '按最近使用'];
$activeFilterCount = ($q !== '' ? 1 : 0) + ($status !== 'all' ? 1 : 0) + ($sort !== 'usage' ? 1 : 0);
?>
<section class="tag-admin">
  <header class="tag-admin-hero">
    <span class="tag-admin-tape" aria-hidden="true"></span>
    <div class="tag-admin-hero-copy">
      <span class="eyebrow"><?= cream_icon('tag', 'icon-sm') ?> TAGS · INDEX</span>
      <h2>给每篇字<br><span class="marker">找一个抽屉。</span></h2>
      <p>把散落在文章里的关键词收成清楚的标签。先看使用情况，再决定重命名、合并或清理。</p>
    </div>
    <aside class="tag-admin-hero-note">
      <span>使用最多</span>
      <strong><?= cream_e((string) ($summary['top_name'] ?? '还没有标签')) ?></strong>
      <small><?= (int) ($summary['top_count'] ?? 0) ?> 篇文章关联</small>
      <a href="/tags.html" target="_blank" rel="noopener"><?= cream_icon('external', 'icon-xs') ?> 查看公开标签</a>
    </aside>
  </header>

  <div class="tag-admin-stats" aria-label="标签概览">
    <article><span>全部标签</span><strong><?= number_format((int) ($summary['total'] ?? 0)) ?></strong><small>包含未使用的标签</small></article>
    <article><span>已有文章</span><strong><?= number_format((int) ($summary['used'] ?? 0)) ?></strong><small>正在被文章引用</small></article>
    <article><span>待整理</span><strong><?= number_format((int) ($summary['empty'] ?? 0)) ?></strong><small>没有文章关联</small></article>
    <article><span>关联总数</span><strong><?= number_format((int) ($summary['links'] ?? 0)) ?></strong><small>文章与标签的连接</small></article>
  </div>

<?php if ($err !== null && isset($errMessages[$err])): ?>
  <div class="alert bad"><?= cream_e($errMessages[$err]) ?></div>
<?php elseif ($ok !== null && isset($okMessages[$ok])): ?>
  <div class="alert good" data-auto-dismiss="5000"><?= cream_e($okMessages[$ok]) ?></div>
<?php endif; ?>

  <div class="tag-admin-toolbar">
    <form class="tag-filter-form" method="get" action="/admin/tags.html" data-admin-filter-panel data-ajax-silent data-admin-filter-label="筛选标签" data-active-filter-count="<?= $activeFilterCount ?>">
      <label class="tag-search-field">
        <span class="tag-search-icon"><?= cream_icon('search', 'icon-xs') ?></span>
        <input name="q" value="<?= cream_e($q) ?>" maxlength="60" placeholder="搜索标签名或 slug" aria-label="搜索标签">
      </label>
      <label class="tag-select-field"><span>状态</span><select name="status" aria-label="按使用状态筛选">
        <?php foreach ($statusLabels as $value => $label): ?>
        <option value="<?= cream_e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= cream_e($label) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label class="tag-select-field"><span>排序</span><select name="sort" aria-label="标签排序">
        <?php foreach ($sortLabels as $value => $label): ?>
        <option value="<?= cream_e($value) ?>"<?= $sort === $value ? ' selected' : '' ?>><?= cream_e($label) ?></option>
        <?php endforeach; ?>
      </select></label>
      <button class="admin-button primary" type="submit">筛选</button>
      <?php if ($q !== '' || $status !== 'all' || $sort !== 'usage'): ?>
      <a class="tag-filter-reset" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="/admin/tags.html">重置</a>
      <?php endif; ?>
    </form>
    <form class="tag-create-form" method="post" action="/admin/tags.html">
      <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
      <label><span>新建标签</span><input name="name" maxlength="60" placeholder="新标签名称" required></label>
      <button class="admin-button ghost" type="submit"><?= cream_icon('plus', 'icon-xs') ?> 添加</button>
    </form>
  </div>

  <div class="tag-admin-bar">
    <div><strong><?= number_format($total) ?></strong> 个匹配标签<?= $q !== '' ? ' · “' . cream_e($q) . '”' : '' ?></div>
    <?php if ((int) ($summary['empty'] ?? 0) > 0): ?>
    <form method="post" action="/admin/tags/cleanup-empty.html" class="inline-form" onsubmit="return confirm('确认清理全部没有文章关联的标签？已有文章的标签不会被删除。')">
      <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
      <button class="link-button danger" type="submit"><?= cream_icon('trash', 'icon-xs') ?> 清理空标签（<?= (int) $summary['empty'] ?>）</button>
    </form>
    <?php endif; ?>
  </div>

<?php if ($tags === []): ?>
  <div class="admin-empty tag-admin-empty">
    <?= cream_icon('tag') ?>
    <h2><?= $q !== '' || $status !== 'all' ? '没有匹配的标签' : '还没有标签' ?></h2>
    <p><?= $q !== '' || $status !== 'all' ? '换一个关键词或筛选条件试试。' : '在文章编辑器里添加标签，或从上面的“新建标签”开始。' ?></p>
    <?php if ($q !== '' || $status !== 'all'): ?><a class="text-link" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="/admin/tags.html">查看全部标签 <?= cream_icon('arrow-right', 'icon-xs') ?></a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="tag-admin-grid">
  <?php foreach ($tags as $tag): ?>
    <?php $tone = $accent((string) $tag['slug']); $progress = max(4, (int) round(((int) $tag['count'] / $maxCount) * 100)); ?>
    <article class="tag-admin-card tag-admin-card--<?= cream_e($tone) ?>">
      <div class="tag-admin-card-top">
        <div class="tag-admin-identity">
          <span class="tag-admin-swatch">#</span>
          <div>
            <h3><?= cream_e((string) $tag['name']) ?></h3>
            <code>/tags/<?= cream_e((string) $tag['slug']) ?>.html</code>
          </div>
        </div>
        <div class="tag-admin-count"><strong><?= (int) $tag['count'] ?></strong><span>篇文章</span></div>
      </div>
      <div class="tag-admin-meter" aria-hidden="true"><span style="width: <?= $progress ?>%"></span></div>
      <div class="tag-admin-meta">
        <span>已发布 <b><?= (int) $tag['published_count'] ?></b></span>
        <span>最近使用 <b><?= cream_e($tag['last_used_at'] !== '' ? cream_date((string) $tag['last_used_at']) : '尚未使用') ?></b></span>
        <span>创建于 <b><?= cream_e($tag['created_at'] !== '' ? cream_date((string) $tag['created_at']) : '—') ?></b></span>
      </div>
      <?php if ((int) $tag['published_count'] > 0): ?>
      <a class="tag-admin-public" href="/tags/<?= cream_e((string) $tag['slug']) ?>.html" target="_blank" rel="noopener">打开公开页 <?= cream_icon('external', 'icon-xs') ?></a>
      <?php else: ?>
      <span class="tag-admin-public is-muted"><?= cream_icon('clock', 'icon-xs') ?> 暂无已发布文章</span>
      <?php endif; ?>
      <details class="tag-admin-actions">
        <summary>管理这个标签 <?= cream_icon('arrow-right', 'icon-xs') ?></summary>
        <div class="tag-admin-action-panel">
          <form method="post" action="/admin/tags/<?= (int) $tag['id'] ?>/rename.html" class="tag-action-line">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <label><span>重命名</span><input name="name" maxlength="60" value="<?= cream_e((string) $tag['name']) ?>" required></label>
            <button class="admin-button ghost" type="submit">保存</button>
          </form>
          <?php if (count($tagOptions) > 1): ?>
          <form method="post" action="/admin/tags/<?= (int) $tag['id'] ?>/merge.html" class="tag-action-line" onsubmit="return confirm('确认把这个标签的文章合并到目标标签？合并后这个标签会消失。')">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <label><span>合并到</span><select name="target_id" aria-label="合并目标">
              <?php foreach ($tagOptions as $target): if ((int) $target['id'] === (int) $tag['id']) { continue; } ?>
              <option value="<?= (int) $target['id'] ?>"><?= cream_e((string) $target['name']) ?></option>
              <?php endforeach; ?>
            </select></label>
            <button class="admin-button ghost" type="submit">合并</button>
          </form>
          <?php endif; ?>
          <?php if ((int) $tag['count'] === 0): ?>
          <form method="post" action="/admin/tags/<?= (int) $tag['id'] ?>/delete.html" class="tag-action-line" onsubmit="return confirm('确认删除这个没有文章的空标签？')">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <span class="tag-action-note">没有文章关联，可以安全删除。</span>
            <button class="link-button danger" type="submit"><?= cream_icon('trash', 'icon-xs') ?> 删除</button>
          </form>
          <?php else: ?>
          <p class="tag-action-note"><?= cream_icon('shield', 'icon-xs') ?> 有文章关联，删除前请先合并到其他标签。</p>
          <?php endif; ?>
        </div>
      </details>
    </article>
  <?php endforeach; ?>
  </div>
  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $total; $pager_prev = $prev_url ?? null; $pager_next = $next_url ?? null; $pager_links = $page_links ?? []; $pager_ajax = true; $pager_label = '标签管理分页'; require dirname(__DIR__) . '/partials/page-pager.php'; ?>
<?php endif; ?>
</section>
