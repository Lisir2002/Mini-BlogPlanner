<?php
$tags = is_array($tags ?? null) ? $tags : [];
$ok = $ok ?? null;
$messages = ['renamed' => '标签已重命名。', 'merged' => '标签已合并。', 'deleted' => '空标签已删除。'];
?>
<section class="admin-page">
  <header class="admin-page-head">
    <div><span class="eyebrow">TAGS</span><h1>标签管理</h1><p><?= count($tags) ?> 个标签。重命名保留 slug，合并会把文章关联转到目标标签。</p></div>
    <a class="admin-button ghost" href="/tags.html" target="_blank" rel="noopener">查看公开标签</a>
  </header>

<?php if (is_string($ok) && isset($messages[$ok])): ?>
  <div class="alert good"><?= cream_e($messages[$ok]) ?></div>
<?php endif; ?>

<?php if ($tags === []): ?>
  <div class="admin-empty"><?= cream_icon('tag') ?><h2>还没有标签</h2><p>在文章编辑器里加标签后，这里会出现。</p></div>
<?php else: ?>
  <div class="tag-admin-list">
  <?php foreach ($tags as $tag): ?>
    <article class="tag-admin-row">
      <div class="tag-admin-title">
        <strong><?= cream_e($tag['name']) ?></strong>
        <span>/tags/<?= cream_e($tag['slug']) ?>.html · <?= (int) $tag['count'] ?> 篇</span>
      </div>
      <form method="post" action="/admin/tags/<?= (int) $tag['id'] ?>/rename.html" class="tag-admin-form">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <input name="name" maxlength="60" value="<?= cream_e($tag['name']) ?>" required aria-label="新的标签名">
        <button class="admin-button ghost" type="submit">重命名</button>
      </form>
      <?php if (count($tags) > 1): ?>
      <form method="post" action="/admin/tags/<?= (int) $tag['id'] ?>/merge.html" class="tag-admin-form" onsubmit="return confirm('确认把这个标签的文章合并到目标标签？')">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <select name="target_id" aria-label="合并目标">
          <?php foreach ($tags as $target): if ($target['id'] === $tag['id']) { continue; } ?>
          <option value="<?= (int) $target['id'] ?>"><?= cream_e($target['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="admin-button ghost" type="submit">合并到</button>
      </form>
      <?php endif; ?>
      <?php if ((int) $tag['count'] === 0): ?>
      <form method="post" action="/admin/tags/<?= (int) $tag['id'] ?>/delete.html" class="inline-form" onsubmit="return confirm('确认删除这个空标签？')">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <button class="link-button danger" type="submit">删除</button>
      </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
