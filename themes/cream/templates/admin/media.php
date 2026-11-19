<?php
$media = is_array($media ?? null) ? $media : [];
$uploaded = $uploaded ?? null;
?>
<section class="admin-page">
  <header class="admin-page-head">
    <div>
      <span class="eyebrow">MEDIA</span>
      <h1>媒体库</h1>
      <p>图片存放在非公开目录，通过受控地址读取。只接受 JPEG、PNG、WebP、GIF，单张不超过 5MB。</p>
    </div>
  </header>

<?php if (is_int($uploaded)): ?>
  <div class="alert good">图片已上传。复制地址或 HTML 片段放到文章正文里。</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <div class="alert good">图片记录与文件已删除。</div>
<?php endif; ?>

  <form method="post" action="/admin/media.html" enctype="multipart/form-data" class="admin-form media-upload">
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <div class="field">
      <label for="file">选择图片</label>
      <label class="cream-file" for="file">
        <input id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required>
        <span class="cream-file-button"><?= cream_icon('layers', 'icon-xs') ?> 选择图片</span>
        <span class="cream-file-name" data-file-name>还没有选择文件</span>
      </label>
      <span class="field-hint">上传后不会自动插入文章，需要在编辑器里引用地址。</span>
    </div>
    <button class="admin-button primary" type="submit">上传图片</button>
  </form>

<?php if ($media === []): ?>
  <div class="admin-empty">
    <?= cream_icon('layers') ?>
    <h2>还没有图片</h2>
    <p>先上传一张，媒体库会记住它的尺寸、类型和地址。</p>
  </div>
<?php else: ?>
  <div class="media-grid">
  <?php foreach ($media as $item): ?>
    <article class="media-card">
      <a class="media-preview" href="<?= cream_e($item->url()) ?>" target="_blank" rel="noopener">
        <img src="<?= cream_e($item->url()) ?>" alt="<?= cream_e($item->originalName) ?>" loading="lazy">
      </a>
      <div class="media-meta">
        <strong><?= cream_e($item->originalName) ?></strong>
        <span><?= (int) $item->width ?>×<?= (int) $item->height ?> · <?= number_format($item->size / 1024, 0) ?> KB</span>
        <code><?= cream_e('<img src="' . $item->url() . '" alt="">') ?></code>
        <form method="post" action="/admin/media/<?= (int) $item->id ?>/delete.html" class="inline-form" onsubmit="return confirm('确认删除这张图片？文章里的引用会失效。')">
          <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
          <button class="link-button danger" type="submit">删除</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
