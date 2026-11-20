<?php
$series = is_array($series ?? null) ? $series : [];
$errors = is_array($errors ?? null) ? $errors : [];
$values = is_array($values ?? null) ? $values : [];
$ok = $ok ?? null;
$messages = ['created' => '系列已创建。', 'updated' => '系列已保存。', 'deleted' => '空系列已删除。'];
?>
<section class="admin-page">
  <header class="admin-page-head">
    <div><span class="eyebrow">SERIES</span><h1>系列管理</h1><p><?= count($series) ?> 个系列。文章只能属于一个系列，公开页按发布时间从早到晚排列。</p></div>
    <a class="admin-button ghost" href="/series.html" target="_blank" rel="noopener">查看公开系列</a>
  </header>

<?php if (is_string($ok) && isset($messages[$ok])): ?>
  <div class="alert good"><?= cream_e($messages[$ok]) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
  <div class="alert bad"><?= cream_e($error) ?></div>
<?php endforeach; ?>

  <form method="post" action="/admin/series.html" class="admin-form series-create-form">
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <span class="admin-hint">新建系列</span>
    <div class="series-create-grid">
      <div class="field"><label for="series-name">系列名</label><input id="series-name" name="name" maxlength="120" value="<?= cream_e($values['name'] ?? '') ?>" placeholder="建站手记" required></div>
      <div class="field"><label for="series-slug">slug</label><input id="series-slug" name="slug" maxlength="80" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= cream_e($values['slug'] ?? '') ?>" placeholder="留空自动生成"><span class="field-hint">公开地址为 /series/{slug}.html。</span></div>
      <div class="field"><label for="series-description">一句话说明</label><input id="series-description" name="description" maxlength="500" value="<?= cream_e($values['description'] ?? '') ?>" placeholder="这条线记录什么，为什么值得从头读。"></div>
      <button class="admin-button primary" type="submit">创建系列</button>
    </div>
  </form>

<?php if ($series === []): ?>
  <div class="admin-empty"><?= cream_icon('layers') ?><h2>还没有系列</h2><p>先建一个系列，再到文章编辑器里把文章归入其中。</p></div>
<?php else: ?>
  <div class="tag-admin-list series-admin-list">
  <?php foreach ($series as $item): ?>
    <article class="tag-admin-row">
      <div class="tag-admin-title">
        <strong><?= cream_e($item['name']) ?></strong>
        <span>/series/<?= cream_e($item['slug']) ?>.html · <?= (int) $item['count'] ?> 篇</span>
      </div>
      <p class="series-admin-desc"><?= cream_e($item['description'] !== '' ? $item['description'] : '还没有写系列说明。') ?></p>
      <form method="post" action="/admin/series/<?= (int) $item['id'] ?>.html" class="tag-admin-form series-admin-edit">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <input name="name" maxlength="120" value="<?= cream_e($item['name']) ?>" required aria-label="系列名">
        <input name="description" maxlength="500" value="<?= cream_e($item['description']) ?>" placeholder="一句话说明" aria-label="系列说明">
        <button class="admin-button ghost" type="submit">保存</button>
      </form>
      <?php if ((int) $item['count'] === 0): ?>
      <form method="post" action="/admin/series/<?= (int) $item['id'] ?>/delete.html" class="inline-form" onsubmit="return confirm('确认删除这个空系列？')">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <button class="link-button danger" type="submit">删除</button>
      </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
