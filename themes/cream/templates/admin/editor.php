<?php
$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$action = (string) ($action ?? '/admin/posts.html');
$post = is_array($post ?? null) ? $post : null;
$postId = $post === null ? 0 : (int) ($post['id'] ?? 0);
$format = (string) ($body_format ?? ($values['body_format'] ?? 'markdown'));
$format = in_array($format, ['markdown', 'html'], true) ? $format : 'markdown';
$contentVersion = max(1, (int) ($values['content_version'] ?? 1));
$updatedAt = (string) ($values['updated_at'] ?? '');
$hasDraft = (bool) ($has_draft ?? false);
$draftVersion = max(0, (int) ($draft_version ?? 0));
$draftUpdatedAt = (string) ($draft_updated_at ?? '');
$draftStale = $hasDraft && (int) ($values['draft_base_version'] ?? $contentVersion) !== $contentVersion;
$healthChecks = is_array($health_checks ?? null) ? $health_checks : [];
$healthBlockers = is_array($health_blockers ?? null) ? $health_blockers : [];
$titleValue = (string) ($values['title'] ?? '');
$slugValue = (string) ($values['slug'] ?? '');
$excerptValue = (string) ($values['excerpt'] ?? '');
$tagsValue = (string) ($values['tags'] ?? '');
$statusValue = (string) ($values['status'] ?? 'draft');
$sourceValue = (string) ($values['body_source'] ?? '');
$bodyHtmlValue = (string) ($body_html_value ?? ($values['body'] ?? ''));
$previewHtml = (string) ($preview_html ?? '');
$headings = is_array($preview_headings ?? null) ? $preview_headings : [];
$stats = is_array($preview_stats ?? null) ? $preview_stats : ['chars' => 0, 'words' => 0, 'reading_minutes' => 1];
$warnings = is_array($preview_warnings ?? null) ? $preview_warnings : [];
$hasSource = trim($sourceValue) !== '';
$bodyReady = trim(strip_tags($previewHtml)) !== '';
$titleReady = trim($titleValue) !== '';
$slugReady = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugValue) === 1;
$excerptReady = trim($excerptValue) !== '';
$tagsReady = trim($tagsValue) !== '';
$markdownUnavailable = $format === 'html' && !$hasSource && $postId > 0;
$published = $statusValue === 'published';
$saveLabel = $hasDraft && $draftUpdatedAt !== ''
    ? '工作稿已保存 ' . date('H:i', strtotime($draftUpdatedAt))
    : ($updatedAt !== '' ? '线上版本 ' . date('H:i', strtotime($updatedAt)) : '新建工作稿');
?>
<section class="admin-page editor-page">
  <header class="editor-page-head">
    <div class="editor-page-title">
      <span class="eyebrow">BACKSTAGE · EDITOR</span>
      <h1><?= cream_e($title ?? '编辑文章') ?></h1>
      <div class="editor-page-state">
        <span class="editor-save-dot"></span>
        <span id="editorSaveState"><?= cream_e($saveLabel) ?></span>
        <span class="editor-version" id="editorVersionLabel">线上 v<?= $contentVersion ?><?= $hasDraft ? ' · 工作稿 d' . $draftVersion : '' ?></span>
        <?php if ($published): ?><span class="editor-online">线上已发布</span><?php endif; ?>
        <?php if ($draftStale): ?><span class="editor-online is-warn">工作稿基于旧线上版本</span><?php endif; ?>
      </div>
    </div>
    <div class="editor-page-actions">
      <?php if ($post !== null): ?><a class="admin-button ghost" href="/admin/posts/<?= $postId ?>/preview.html" target="_blank" rel="noopener"><?= cream_icon('external', 'icon-xs') ?> 预览</a><?php endif; ?>
      <a class="admin-button ghost" href="/admin/posts.html"><?= cream_icon('arrow-left', 'icon-xs') ?> 返回列表</a>
      <button class="admin-button primary" type="submit" form="editorForm" id="editorSaveTop"><?= cream_icon('check', 'icon-xs') ?> 发布工作稿</button>
    </div>
  </header>

  <div class="editor-errors alert bad" id="editorErrors" role="alert"<?= $errors === [] ? ' hidden' : '' ?>>
    <?php foreach ($errors as $error): ?><p><?= cream_e($error) ?></p><?php endforeach; ?>
  </div>

  <form method="post" action="<?= cream_e($action) ?>" class="editor-form" id="editorForm" data-ajax="off"
        data-editor data-post-id="<?= $postId ?>" data-updated-at="<?= cream_e($hasDraft ? $draftUpdatedAt : $updatedAt) ?>"
        data-preview-url="/admin/posts/preview-body.html"
        data-draft-url="<?= $postId > 0 ? '/admin/posts/' . $postId . '/draft' : '' ?>"
        data-draft-discard-url="<?= $postId > 0 ? '/admin/posts/' . $postId . '/draft/discard' : '' ?>"
        data-draft-version="<?= $draftVersion ?>">
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <input type="hidden" name="body_format" id="bodyFormat" value="<?= cream_e($format) ?>">
    <input type="hidden" name="content_version" id="contentVersion" value="<?= $contentVersion ?>">
    <input type="hidden" name="draft_version" id="draftVersion" value="<?= $draftVersion ?>">

    <div class="editor-draft-banner" id="editorDraftBanner" hidden>
      <span>发现一份本地草稿：<b id="editorDraftTime"></b></span>
      <div><button type="button" class="editor-mini-button" id="editorDraftRestore">恢复草稿</button><button type="button" class="editor-mini-button ghost" id="editorDraftDiscard">丢弃</button></div>
    </div>

    <?php if ($hasDraft): ?>
    <div class="editor-server-draft" id="editorServerDraft">
      <span>已恢复未发布工作稿 <b>d<?= $draftVersion ?></b><?= $draftUpdatedAt !== '' ? '，保存于 ' . cream_e(date('m.d H:i', strtotime($draftUpdatedAt))) : '' ?>。线上文章仍保持 v<?= $contentVersion ?>。</span>
      <div><button type="button" class="editor-mini-button ghost" id="editorDraftClear">丢弃工作稿</button></div>
    </div>
    <?php endif; ?>

    <div class="editor-conflict" id="editorConflict" hidden role="alert">
      <div><b>版本冲突</b><p id="editorConflictText">这篇文稿已在另一个窗口保存。</p></div>
      <div class="editor-conflict-actions">
        <button type="button" class="editor-mini-button" data-conflict="reload">载入最新</button>
        <button type="button" class="editor-mini-button" data-conflict="overwrite">覆盖保存</button>
        <button type="button" class="editor-mini-button ghost" data-conflict="dismiss">稍后处理</button>
      </div>
    </div>

    <div class="editor-workspace">
      <aside class="editor-rail" aria-label="手稿目录">
        <div class="editor-rail-head"><strong>手稿目录</strong><span>WORK DRAFT <?= str_pad((string) $draftVersion, 2, '0', STR_PAD_LEFT) ?></span></div>
        <nav class="editor-outline">
          <a class="is-current" href="#title"><span>01</span><b>标题</b><i></i></a>
          <a href="#editorSource"><span>02</span><b>正文</b><i></i></a>
          <?php foreach ($headings as $heading): ?>
            <?php if (trim((string) ($heading['text'] ?? '')) === trim($titleValue)) { continue; } ?>
            <a class="editor-outline-sub level-<?= (int) ($heading['level'] ?? 2) ?>" href="#editorPreview"><span></span><b><?= cream_e(mb_strimwidth((string) ($heading['text'] ?? ''), 0, 24, '…')) ?></b></a>
          <?php endforeach; ?>
          <a href="#editorInspector"><span>03</span><b>发布</b><i></i></a>
        </nav>
        <div class="editor-rail-stats">
          <div><b id="railChars"><?= (int) ($stats['chars'] ?? 0) ?></b><span>字数</span></div>
          <div><b id="railMinutes"><?= (int) ($stats['reading_minutes'] ?? 1) ?></b><span>分钟</span></div>
        </div>
        <div class="editor-rail-note"><b>写作提示</b><p>标题留一点呼吸，正文每段不超过三行；Markdown 里的原始 HTML 会按文字显示。</p></div>
      </aside>

      <section class="editor-paper" id="editorPaper">
        <div class="editor-paper-head">
          <span class="editor-tape" aria-hidden="true"></span>
          <label class="sr-only" for="title">标题</label>
          <input class="editor-title" id="title" name="title" maxlength="200" value="<?= cream_e($titleValue) ?>" placeholder="在这里写下标题" required autofocus>
          <div class="editor-doc-meta">
            <span><?= cream_e($updatedAt !== '' ? date('Y.m.d', strtotime($updatedAt)) : date('Y.m.d')) ?></span>
            <span class="editor-slug-chip"><?= cream_e('/posts/' . ($slugValue !== '' ? $slugValue : 'new-post') . '.html') ?></span>
            <span class="editor-format-chip" id="editorFormatChip"><?= $format === 'markdown' ? 'Markdown' : 'HTML' ?></span>
          </div>
        </div>

        <div class="editor-toolbar" role="toolbar" aria-label="正文工具">
          <div class="editor-modes" role="tablist" aria-label="正文格式">
            <button type="button" class="editor-mode<?= $format === 'markdown' ? ' is-active' : '' ?>" data-editor-mode="markdown" role="tab" aria-selected="<?= $format === 'markdown' ? 'true' : 'false' ?>"<?= $markdownUnavailable ? ' disabled title="旧 HTML 文章保持 HTML 模式"' : '' ?>>Markdown</button>
            <button type="button" class="editor-mode<?= $format === 'html' ? ' is-active' : '' ?>" data-editor-mode="html" role="tab" aria-selected="<?= $format === 'html' ? 'true' : 'false' ?>">HTML</button>
          </div>
          <span class="editor-tool-sep" aria-hidden="true"></span>
          <div class="editor-format-tools" data-markdown-tools>
            <button type="button" class="editor-tool" data-editor-insert="h2" title="插入二级标题" aria-label="插入二级标题">H2</button>
            <button type="button" class="editor-tool" data-editor-insert="bold" title="加粗" aria-label="加粗"><strong>B</strong></button>
            <button type="button" class="editor-tool" data-editor-insert="italic" title="斜体" aria-label="斜体"><em>I</em></button>
            <button type="button" class="editor-tool" data-editor-insert="link" title="插入链接" aria-label="插入链接"><?= cream_icon('link', 'icon-xs') ?></button>
            <button type="button" class="editor-tool" data-editor-insert="quote" title="插入引用" aria-label="插入引用">“</button>
            <button type="button" class="editor-tool" data-editor-insert="list" title="插入列表" aria-label="插入列表">≣</button>
            <button type="button" class="editor-tool" data-editor-insert="code" title="插入代码块" aria-label="插入代码块">&lt;/&gt;</button>
          </div>
          <div class="editor-toolbar-right">
            <button type="button" class="editor-command-button" id="editorCommandBtn" title="打开插入命令"><kbd>Cmd K</kbd> 命令</button>
            <span class="editor-format-label" id="editorFormatLabel"><?= strtoupper($format) ?></span>
          </div>
          <div class="editor-command-menu" id="editorCommandMenu" hidden>
            <button type="button" data-editor-insert="h2"><span>##</span>二级标题</button>
            <button type="button" data-editor-insert="quote"><span>&gt;</span>引用</button>
            <button type="button" data-editor-insert="list"><span>-</span>列表</button>
            <button type="button" data-editor-insert="code"><span>```</span>代码块</button>
            <button type="button" data-editor-insert="table"><span>|</span>表格</button>
            <button type="button" data-editor-insert="hr"><span>---</span>分割线</button>
            <button type="button" data-editor-insert="link"><span>[]()</span>链接</button>
          </div>
        </div>

        <div class="editor-stage">
          <div class="editor-pane editor-source-pane" data-editor-pane="source">
            <div class="editor-pane-head"><span id="editorPaneLabel"><?= $format === 'markdown' ? 'MARKDOWN' : 'HTML' ?></span><span class="editor-pane-grow">UTF-8</span><span id="editorSourceStats"><?= (int) ($stats['chars'] ?? 0) ?> 字</span></div>
            <div class="editor-source-shell" id="editorSource">
              <label class="sr-only" for="bodySource">Markdown 正文</label>
              <textarea id="bodySource" name="body_source" class="editor-textarea" spellcheck="false" placeholder="# 从这里开始写&#10;&#10;正文支持 Markdown：**加粗**、引用、列表、代码块和表格。"><?= cream_e($sourceValue) ?></textarea>
              <label class="sr-only" for="bodyHtml">HTML 正文</label>
              <textarea id="bodyHtml" name="body" class="editor-textarea editor-textarea-html" spellcheck="false" placeholder="&lt;p&gt;这里编辑 HTML 正文&lt;/p&gt;"><?= cream_e($bodyHtmlValue) ?></textarea>
            </div>
            <div class="editor-pane-note" data-html-note hidden>HTML 模式只保留白名单内的标签；脚本、样式、表单和内嵌页面会被移除。</div>
          </div>
          <div class="editor-pane editor-preview-pane" data-editor-pane="preview">
            <div class="editor-pane-head"><span class="editor-live-dot"></span><span>LIVE PREVIEW</span><span class="editor-pane-grow">与发布页一致</span></div>
            <div class="editor-preview post-body" id="editorPreview" aria-live="off"><?= $previewHtml ?></div>
            <div class="editor-preview-empty" id="editorPreviewEmpty"<?= $bodyReady ? ' hidden' : '' ?>>还没有正文，先从左侧写第一段。</div>
            <div class="editor-warnings" id="editorWarnings"<?= $warnings === [] ? ' hidden' : '' ?>>
              <?php foreach ($warnings as $warning): ?><p><?= cream_e($warning) ?></p><?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="editor-statusbar">
          <span id="editorCursor">第 1 行，第 1 列</span>
          <span id="editorStats"><?= (int) ($stats['chars'] ?? 0) ?> 字 · 约 <?= (int) ($stats['reading_minutes'] ?? 1) ?> 分钟</span>
          <span class="editor-status-grow"></span>
          <span class="editor-draft-note">工作稿自动保存到后台</span>
          <span class="editor-save-status" id="editorSaveStatus"><?= cream_e($saveLabel) ?></span>
          <span class="editor-key"><kbd>Cmd+S</kbd> 存工作稿</span>
        </div>
      </section>

      <aside class="editor-inspector" id="editorInspector" aria-label="发布检查">
        <div class="editor-inspector-head">
          <div><h2>发布检查</h2><p id="editorInspectorSummary">先补齐标题、正文和固定链接</p></div>
          <span class="editor-stamp<?= $published ? ' is-online' : '' ?>" id="editorStamp"><?= $published ? 'LIVE' : 'DRAFT' ?></span>
        </div>
        <div class="editor-inspector-tabs" role="tablist" aria-label="发布检查视图">
          <button type="button" class="is-active" data-inspector-tab="publish" role="tab" aria-selected="true">发布</button>
          <button type="button" data-inspector-tab="check" role="tab" aria-selected="false">检查</button>
          <button type="button" disabled title="版本历史在后续阶段开放">历史</button>
        </div>

        <div data-inspector-panel="publish">
          <div class="editor-field">
            <label for="slug">固定链接</label>
            <div class="editor-slug-field"><span>/posts/</span><input id="slug" name="slug" maxlength="190" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= cream_e($slugValue) ?>" placeholder="weekend-journal" required><span>.html</span></div>
            <span class="editor-hint">小写字母、数字和短横线。</span>
          </div>
          <div class="editor-field">
            <label for="excerpt">摘要</label>
            <textarea id="excerpt" name="excerpt" maxlength="500" rows="3" placeholder="用一两句话说明这篇文字。"><?= cream_e($excerptValue) ?></textarea>
            <span class="editor-hint">40–120 字更容易被读者看见。</span>
          </div>
          <div class="editor-field">
            <label for="tags">标签</label>
            <input id="tags" name="tags" maxlength="500" value="<?= cream_e($tagsValue) ?>" placeholder="生活, 手账">
            <span class="editor-hint">用逗号分隔，最多 8 个。</span>
          </div>
          <div class="editor-field">
            <label for="status">线上状态</label>
            <select id="status" name="status">
              <option value="draft" <?= $statusValue === 'draft' ? 'selected' : '' ?>>草稿</option>
              <option value="published" <?= $statusValue === 'published' ? 'selected' : '' ?>>发布</option>
            </select>
            <span class="editor-hint">保存为草稿不会改变线上文章。</span>
          </div>
          <div class="editor-summary" id="editorSummary">
            <span>当前格式</span><b id="editorSummaryFormat"><?= $format === 'markdown' ? 'Markdown' : 'HTML' ?></b>
            <span>线上版本</span><b id="editorSummaryVersion">v<?= $contentVersion ?></b>
            <span>工作稿</span><b id="editorSummaryDraft">d<?= $draftVersion ?></b>
            <span>阅读时长</span><b id="editorSummaryMinutes"><?= (int) ($stats['reading_minutes'] ?? 1) ?> 分钟</b>
          </div>
          <button class="admin-button primary full editor-save-button" type="submit" id="editorSaveButton">发布工作稿</button>
          <p class="editor-hint editor-save-hint">输入会自动保存工作稿；点“发布工作稿”才会写入线上文章。</p>
        </div>

        <div data-inspector-panel="check" hidden>
          <div class="editor-check-list" id="editorCheckList">
            <?php foreach ($healthChecks as $check): ?>
              <?php $ok = (bool) ($check['ok'] ?? false); $level = (string) ($check['level'] ?? 'warning'); ?>
              <span class="editor-check<?= $ok ? ' is-pass' : ($level === 'blocker' ? ' is-block' : ($level === 'warning' ? ' is-warn' : ' is-info')) ?>" data-check="<?= cream_e((string) ($check['id'] ?? '')) ?>" title="<?= cream_e((string) ($check['message'] ?? '')) ?>"><i><?= $ok ? '✓' : ($level === 'blocker' ? '!' : ($level === 'warning' ? '?' : 'i')) ?></i><?= cream_e((string) ($check['label'] ?? '')) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="editor-warning-box" id="editorWarningBox"<?= $warnings === [] ? ' hidden' : '' ?>>
            <b>预览提醒</b>
            <?php foreach ($warnings as $warning): ?><p><?= cream_e($warning) ?></p><?php endforeach; ?>
          </div>
          <p class="editor-hint">带 “!” 的项目会阻止发布；带 “?” 的提醒可以先发布再补。</p>
        </div>
      </aside>
    </div>

    <div class="editor-mobile-bar" aria-label="手机编辑器操作">
      <button type="button" id="editorMobileOutline"><?= cream_icon('menu', 'icon-xs') ?>目录</button>
      <button type="button" id="editorMobilePreview"><?= cream_icon('external', 'icon-xs') ?><span>预览</span></button>
      <button type="submit" class="is-primary" form="editorForm"><?= cream_icon('check', 'icon-xs') ?>发布</button>
    </div>
  </form>
</section>
