<?php
$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$action = (string) ($action ?? '/admin/posts.html');
?>
<section class="admin-page admin-editor">
  <header class="admin-page-head">
    <div><span class="eyebrow">EDITOR</span><h1><?= cream_e($title ?? '编辑文章') ?></h1></div>
    <div class="admin-page-actions">
      <?php if (is_array($post ?? null) && isset($post['id'])): ?><a class="admin-button ghost" href="/admin/posts/<?= (int) $post['id'] ?>/preview.html" target="_blank" rel="noopener"><?= cream_icon('external', 'icon-xs') ?> 预览</a><?php endif; ?>
      <a class="admin-button ghost" href="/admin/posts.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 返回列表</a>
    </div>
  </header>

<?php foreach ($errors as $error): ?>
  <div class="alert bad"><?= cream_e($error) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= cream_e($action) ?>" class="admin-form editor-form">
  <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
  <div class="editor-grid">
    <div class="editor-main">
      <div class="field"><label for="title">标题</label><input id="title" name="title" maxlength="200" value="<?= cream_e($values['title'] ?? '') ?>" required autofocus></div>
      <div class="field"><label for="slug">slug</label><input id="slug" name="slug" maxlength="190" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= cream_e($values['slug'] ?? '') ?>" required><span class="field-hint">小写字母、数字和短横线；公开地址为 /posts/{slug}.html。</span></div>
      <div class="field"><label for="excerpt">摘要</label><input id="excerpt" name="excerpt" maxlength="500" value="<?= cream_e($values['excerpt'] ?? '') ?>"></div>
      <div class="field"><label for="tags">标签</label><input id="tags" name="tags" maxlength="500" value="<?= cream_e($values['tags'] ?? '') ?>" placeholder="生活, 手账"><span class="field-hint">用逗号分隔，最多 8 个；同名标签会自动复用。</span></div>
      <div class="field"><label for="body">正文 HTML</label><textarea id="body" name="body" rows="18" required><?= cream_e($values['body'] ?? '') ?></textarea><span class="field-hint">只保留段落、标题、列表、引用、代码块、链接和图片；脚本、样式、表单与事件属性会被移除。</span></div>
    </div>
    <aside class="editor-side">
      <div class="field"><label for="status">状态</label>
        <select id="status" name="status">
          <option value="draft" <?= ($values['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>草稿</option>
          <option value="published" <?= ($values['status'] ?? 'draft') === 'published' ? 'selected' : '' ?>>发布</option>
        </select>
      </div>
      <button class="admin-button primary full" type="submit">保存</button>
      <p class="admin-hint">发布时会写入发布时间；改回草稿会清空发布时间。</p>
    </aside>
  </div>
</form>
</section>
