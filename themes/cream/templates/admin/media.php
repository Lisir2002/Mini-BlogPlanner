<?php
use Core\Content\MediaRepository;

$media = is_array($media ?? null) ? $media : [];
$summary = is_array($summary ?? null) ? $summary : ['total' => 0, 'bytes' => 0, 'latest_at' => null, 'types' => []];
$total = (int) ($total ?? count($media));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$filters = is_array($filters ?? null) ? $filters : ['q' => '', 'type' => '', 'sort' => 'newest'];
$q = (string) ($filters['q'] ?? '');
$type = (string) ($filters['type'] ?? '');
$sort = (string) ($filters['sort'] ?? 'newest');
$hasFilters = (bool) ($has_filters ?? false);
$activeFilterCount = (int) ($active_filter_count ?? 0);
$returnUrl = (string) ($return_url ?? '/admin/media.html');
$uploaded = (int) ($uploaded ?? 0);
$failed = (int) ($failed ?? 0);
$uploadError = (string) ($upload_error ?? '');
$deleted = (int) ($deleted ?? 0);
$typeLabels = ['jpg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WebP', 'gif' => 'GIF'];
$bytesLabel = static function (int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
};
$latest = isset($summary['latest_at']) && is_string($summary['latest_at']) && $summary['latest_at'] !== ''
    ? substr(str_replace('-', '.', $summary['latest_at']), 0, 16)
    : '还没有上传';
?>
<section class="admin-page media-admin">
  <div class="media-console">
    <header class="media-console-head">
      <div>
        <span class="eyebrow">MEDIA · LIBRARY</span>
        <h2>媒体库</h2>
        <p>共 <?= number_format((int) ($summary['total'] ?? $total)) ?> 张图片，每页十张。图片存放在非公开目录，通过受控地址读取。</p>
      </div>
    </header>

    <div class="media-summary" aria-label="媒体库概览">
      <div class="media-summary-item"><small>图片</small><strong><?= number_format((int) ($summary['total'] ?? $total)) ?></strong></div>
      <div class="media-summary-item"><small>占用</small><strong><?= cream_e($bytesLabel((int) ($summary['bytes'] ?? 0))) ?></strong></div>
      <div class="media-summary-item media-summary-latest"><small>最近上传</small><strong><?= cream_e($latest) ?></strong></div>
    </div>

  </div>

  <div class="media-filter-shell">
    <form class="media-filterbar<?= $hasFilters ? ' has-filter' : '' ?>" method="get" action="/admin/media.html" role="search" data-admin-filter-panel data-ajax-silent data-admin-filter-label="筛选图片" data-active-filter-count="<?= $activeFilterCount ?>">
      <label class="media-search">
        <span class="visually-hidden">搜索图片</span>
        <?= cream_icon('search', 'icon-sm') ?>
        <input type="search" name="q" value="<?= cream_e($q) ?>" placeholder="搜索文件名或编号" autocomplete="off" maxlength="80">
      </label>
      <label class="media-select-field">
        <span class="visually-hidden">按格式筛选</span>
        <select name="type" aria-label="按格式筛选">
          <option value="">全部格式</option>
          <?php foreach ($typeLabels as $key => $label): ?>
          <option value="<?= cream_e($key) ?>"<?= $type === $key ? ' selected' : '' ?>><?= cream_e($label) ?>（<?= (int) ($summary['types'][$key] ?? 0) ?>）</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="media-select-field">
        <span class="visually-hidden">按画幅筛选</span>
        <select name="shape" aria-label="按画幅筛选">
          <option value="">全部画幅</option>
          <?php foreach (MediaRepository::SHAPES as $key => $label): ?>
          <option value="<?= cream_e($key) ?>"<?= ($filters['shape'] ?? '') === $key ? ' selected' : '' ?>><?= cream_e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="media-select-field">
        <span class="visually-hidden">按体积筛选</span>
        <select name="weight" aria-label="按体积筛选">
          <option value="">全部体积</option>
          <?php foreach (MediaRepository::WEIGHTS as $key => $label): ?>
          <option value="<?= cream_e($key) ?>"<?= ($filters['weight'] ?? '') === $key ? ' selected' : '' ?>><?= cream_e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="media-select-field">
        <span class="visually-hidden">按上传时间筛选</span>
        <select name="period" aria-label="按上传时间筛选">
          <option value="">全部时间</option>
          <?php foreach (MediaRepository::PERIODS as $key => $label): ?>
          <option value="<?= cream_e($key) ?>"<?= ($filters['period'] ?? '') === $key ? ' selected' : '' ?>><?= cream_e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="media-select-field media-select-sort">
        <span class="visually-hidden">排序方式</span>
        <select name="sort" aria-label="排序方式">
          <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>最近上传</option>
          <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>最早上传</option>
          <option value="largest"<?= $sort === 'largest' ? ' selected' : '' ?>>文件最大</option>
          <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>名称 A-Z</option>
        </select>
      </label>
      <button class="admin-button ghost media-filter-submit" type="submit">筛选</button>
      <span class="media-result">筛选后 <strong><?= $total ?></strong> 张</span>
      <?php if ($hasFilters): ?><a class="media-clear" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="/admin/media.html">清空</a><?php endif; ?>
    </form>
  </div>

<?php if ($uploaded > 0 || $failed > 0 || $deleted > 0): ?>
  <div class="admin-flash media-flash" role="status"<?= $failed > 0 ? '' : ' data-auto-dismiss="5000"' ?>>
    <?= cream_icon('spark', 'icon-sm') ?>
    <span>
      <?php if ($uploaded > 0): ?>已上传 <?= $uploaded ?> 张，可以复制地址放进文章。<?php endif; ?>
      <?php if ($failed > 0): ?><?= $uploaded > 0 ? ' ' : '' ?><?= $failed ?> 张未上传<?= $uploadError !== '' ? '：' . cream_e($uploadError) : '。' ?><?php endif; ?>
      <?php if ($deleted > 0): ?>已删除 <?= $deleted ?> 张图片及文件。<?php endif; ?>
    </span>
  </div>
<?php endif; ?>

  <form class="media-upload" method="post" action="/admin/media.html" enctype="multipart/form-data" data-media-upload>
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <label class="media-drop" for="mediaFiles" data-media-drop>
      <input id="mediaFiles" name="file[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple required data-media-files>
      <span class="media-drop-icon"><?= cream_icon('image', 'icon-sm') ?></span>
      <span class="media-drop-copy">
        <strong>拖入图片，或点此选择</strong>
        <small>JPEG / PNG / WebP / GIF · 单张不超过 5MB · 最多 5 张</small>
      </span>
      <span class="media-picked" data-media-picked aria-live="polite">还没有选择文件</span>
    </label>
    <button class="admin-button primary media-upload-submit" type="submit"><?= cream_icon('plus', 'icon-xs') ?> 上传</button>
  </form>

<?php if ($media === []): ?>
  <div class="admin-empty media-empty">
    <?= cream_icon('layers') ?>
    <?php if ($hasFilters): ?>
    <h2>没有符合条件的图片</h2>
    <p>换个关键词或格式，或者清空筛选看看全部。</p>
    <a class="admin-button ghost" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="/admin/media.html">清空筛选</a>
    <?php else: ?>
    <h2>还没有图片</h2>
    <p>先上传一张，媒体库会记住它的尺寸、格式、大小和地址。</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <form class="media-bulk-form" id="mediaBulkForm" method="post" action="/admin/media/bulk-delete.html" data-media-bulk-form>
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <input type="hidden" name="return" value="<?= cream_e($returnUrl) ?>">
    <div class="media-list-head">
      <label class="media-select-all"><input type="checkbox" data-media-select-all> 全选本页</label>
      <span>第 <?= $page ?> / <?= $pages ?> 页 · 共 <?= $total ?> 张</span>
    </div>
    <div class="media-grid" data-media-grid>
    <?php foreach ($media as $item): ?>
      <?php
      $mimeKinds = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
      $kind = $mimeKinds[$item->mime] ?? 'jpg';
      $htmlSnippet = '<img src="' . $item->url() . '" alt="' . $item->originalName . '">';
      ?>
      <article class="media-card" data-media-item
        data-media-id="<?= (int) $item->id ?>"
        data-media-name="<?= cream_e($item->originalName) ?>"
        data-media-url="<?= cream_e($item->url()) ?>"
        data-media-html="<?= cream_e($htmlSnippet) ?>"
        data-media-size="<?= (int) $item->size ?>"
        data-media-width="<?= (int) $item->width ?>"
        data-media-height="<?= (int) $item->height ?>"
        data-media-created="<?= cream_e($item->createdAt) ?>"
        data-media-type="<?= cream_e($typeLabels[$kind] ?? '图片') ?>">
        <label class="media-check" title="选择这张图片">
          <input type="checkbox" name="ids[]" value="<?= (int) $item->id ?>" data-media-check>
          <span class="visually-hidden">选择 <?= cream_e($item->originalName) ?></span>
        </label>
        <a class="media-preview" href="<?= cream_e($item->url()) ?>" target="_blank" rel="noopener" data-media-open>
          <img src="<?= cream_e($item->url()) ?>" alt="<?= cream_e($item->originalName) ?>" loading="lazy" decoding="async">
          <span class="media-kind"><?= cream_e($typeLabels[$kind] ?? 'IMG') ?></span>
        </a>
        <div class="media-card-body">
          <strong class="media-name" title="<?= cream_e($item->originalName) ?>"><?= cream_e($item->originalName) ?></strong>
          <span class="media-meta-line"><?= (int) $item->width ?>×<?= (int) $item->height ?> · <?= cream_e($bytesLabel($item->size)) ?> · <?= cream_e(substr(str_replace('-', '.', $item->createdAt), 0, 10)) ?></span>
          <div class="media-card-actions">
            <button type="button" class="media-icon-action" data-copy-url><?= cream_icon('link', 'icon-xs') ?><span>链接</span></button>
            <button type="button" class="media-icon-action" data-copy-html><?= cream_icon('code-inline', 'icon-xs') ?><span>HTML</span></button>
            <button type="button" class="media-icon-action" data-media-open><?= cream_icon('external', 'icon-xs') ?><span>详情</span></button>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <div class="media-bulk-bar" hidden data-media-bulk-bar>
      <strong><span data-media-selected-count>0</span> 张已选</strong>
      <button type="button" class="link-button" data-media-clear>取消选择</button>
      <button class="admin-button danger" type="submit">删除选中</button>
    </div>
  </form>

  <?php $pager_page = $page; $pager_pages = $pages; $pager_total = $total; $pager_prev = $prev_url ?? null; $pager_next = $next_url ?? null; $pager_links = $page_links ?? []; $pager_ajax = true; $pager_label = '媒体分页'; require dirname(__DIR__) . '/partials/page-pager.php'; ?>

  <dialog class="media-dialog" data-media-dialog aria-labelledby="mediaDialogTitle">
    <div class="media-dialog-sheet">
      <header class="media-dialog-head">
        <div><span class="eyebrow">MEDIA · DETAIL</span><h2 id="mediaDialogTitle" data-detail-name>图片详情</h2></div>
        <button class="media-dialog-close" type="button" data-media-close aria-label="关闭详情"><?= cream_icon('close', 'icon-sm') ?></button>
      </header>
      <div class="media-dialog-body">
        <div class="media-dialog-preview"><img alt="" data-detail-image></div>
        <div class="media-dialog-info">
          <dl class="media-detail-meta">
            <div><dt>格式</dt><dd data-detail-type>—</dd></div>
            <div><dt>尺寸</dt><dd data-detail-size>—</dd></div>
            <div><dt>文件</dt><dd data-detail-bytes>—</dd></div>
            <div><dt>上传</dt><dd data-detail-created>—</dd></div>
          </dl>
          <form class="media-rename" method="post" action="" data-media-rename>
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <input type="hidden" name="return" value="<?= cream_e($returnUrl) ?>">
            <label for="mediaName">显示名称</label>
            <div class="media-rename-row"><input id="mediaName" name="name" type="text" maxlength="190" required data-detail-name-input><button class="admin-button ghost" type="submit">保存</button></div>
          </form>
          <div class="media-copy-field">
            <label for="mediaUrl">图片地址</label>
            <div><input id="mediaUrl" type="text" readonly data-detail-url><button type="button" class="link-button" data-copy-target="mediaUrl">复制</button></div>
          </div>
          <div class="media-copy-field">
            <label for="mediaHtml">HTML 片段</label>
            <div><input id="mediaHtml" type="text" readonly data-detail-html><button type="button" class="link-button" data-copy-target="mediaHtml">复制</button></div>
          </div>
          <div class="media-dialog-actions">
            <a class="admin-button ghost" href="#" target="_blank" rel="noopener" data-detail-open>打开原图 <?= cream_icon('external', 'icon-xs') ?></a>
            <form method="post" action="" data-media-delete onsubmit="return confirm('确认删除这张图片？文章里的引用会失效。')">
              <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
              <input type="hidden" name="return" value="<?= cream_e($returnUrl) ?>">
              <button class="admin-button danger" type="submit">删除这张</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </dialog>
<?php endif; ?>
</section>
