<?php
$post = is_array($post ?? null) ? $post : [];
$revisions = is_array($revisions ?? null) ? $revisions : [];
$selected = is_array($selected ?? null) ? $selected : null;
$currentText = (string) ($current_text ?? '');
$selectedText = (string) ($selected_text ?? '');
$diff = is_array($diff ?? null) ? $diff : [];
$fields = is_array($fields ?? null) ? $fields : [];
$draft = is_array($draft ?? null) ? $draft : null;
$postId = (int) ($post['id'] ?? 0);
$onlineVersion = (int) ($post['content_version'] ?? 1);
$selectedVersion = (int) ($selected_version ?? 0);
$baseVersion = (int) ($base_version ?? $onlineVersion);
$baseLabel = (string) ($base_label ?? '线上 v' . $onlineVersion);
$compareVersion = (int) ($compare_version ?? 0);
$baseIsOnline = (bool) ($base_is_online ?? true);
$draftVersion = (int) ($draft['draft_version'] ?? 0);
$hasChanges = false;
foreach ($diff as $row) {
    if (in_array($row['type'] ?? '', ['add', 'remove'], true)) {
        $hasChanges = true;
        break;
    }
}
?>
<section class="admin-page revision-page">
  <header class="admin-page-head">
    <div>
      <span class="eyebrow">HISTORY</span>
      <p>线上当前是 v<?= $onlineVersion ?>。每次发布都会留下快照，保留最近 <?= (int) ($retention ?? 50) ?> 个版本。</p>
    </div>
    <div class="revision-head-actions">
      <a class="admin-button ghost" href="/admin/posts/<?= $postId ?>.html">返回编辑器</a>
    </div>
  </header>

<?php if ($revisions === []): ?>
  <div class="admin-empty">
    <?= cream_icon('layers') ?>
    <h2>还没有版本快照</h2>
    <p>编辑并发布一次后，这里会出现可对比、可恢复的历史版本。</p>
  </div>
<?php else: ?>
  <div class="revision-layout">
    <aside class="revision-list" aria-label="版本列表">
      <div class="revision-list-head">
        <strong>发布版本</strong>
        <span><?= count($revisions) ?> 个快照</span>
      </div>
      <?php foreach ($revisions as $revision): ?>
        <?php
        $version = (int) ($revision['version'] ?? 0);
        $isCurrent = $version === $onlineVersion;
        $isSelected = $version === $selectedVersion;
        $date = (string) ($revision['published_at'] ?? $revision['created_at'] ?? '');
        ?>
        <?php $compareQuery = $compareVersion > 0 && $version !== $compareVersion ? '&compare=' . $compareVersion : ''; ?>
        <a class="revision-item<?= $isSelected ? ' is-selected' : '' ?><?= $isCurrent ? ' is-online' : '' ?>"
           href="/admin/posts/<?= $postId ?>/revisions?version=<?= $version ?><?= $compareQuery ?>"
           <?= $isSelected ? 'aria-current="page"' : '' ?>>
          <span class="revision-version">v<?= $version ?></span>
          <span class="revision-copy">
            <strong><?= cream_e((string) ($revision['title'] ?? '')) ?></strong>
            <small><?= cream_e($date) ?> · <?= cream_e(strtoupper((string) ($revision['body_format'] ?? 'html'))) ?></small>
          </span>
          <?php if ($isCurrent): ?><span class="revision-tag">线上</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </aside>

    <section class="revision-detail">
      <?php if ($selected === null): ?>
        <div class="admin-empty"><h2>选择一个版本</h2><p>左侧列表里点选版本后，这里会显示它与线上的差异。</p></div>
      <?php else: ?>
        <header class="revision-detail-head">
          <div>
            <span class="eyebrow">COMPARE</span>
            <h2>v<?= $selectedVersion ?> 对比 <?= cream_e($baseLabel) ?></h2>
            <p><?= cream_e((string) ($selected['title'] ?? '')) ?> · 基准 <?= cream_e($baseLabel) ?></p>
          </div>
          <div class="revision-badges">
            <span><?= cream_e((string) ($selected['status'] ?? 'draft')) ?></span>
            <span><?= cream_e(strtoupper((string) ($selected['body_format'] ?? 'html'))) ?></span>
            <span><?= (int) ($selected['reading_minutes'] ?? 1) ?> 分钟</span>
          </div>
        </header>

        <form class="revision-compare" method="get" action="/admin/posts/<?= $postId ?>/revisions">
          <input type="hidden" name="version" value="<?= $selectedVersion ?>">
          <label for="revisionCompare">对比基准</label>
          <select id="revisionCompare" name="compare" onchange="this.form.submit()">
            <option value=""<?= $baseIsOnline ? ' selected' : '' ?>>线上 v<?= $onlineVersion ?></option>
            <?php foreach ($revisions as $revision): ?>
              <?php $optionVersion = (int) ($revision['version'] ?? 0); ?>
              <?php if ($optionVersion === $selectedVersion) { continue; } ?>
              <option value="<?= $optionVersion ?>"<?= $optionVersion === $baseVersion ? ' selected' : '' ?>>v<?= $optionVersion ?> · <?= cream_e((string) ($revision['published_at'] ?? $revision['created_at'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="admin-button ghost" type="submit">切换</button></noscript>
        </form>

        <?php if ($draftVersion > 0): ?>
          <div class="revision-note">当前已有工作稿 d<?= $draftVersion ?>。恢复这个版本会覆盖工作稿，但不会改动线上文章。</div>
        <?php endif; ?>

        <div class="revision-fields">
          <table>
            <thead><tr><th>字段</th><th>v<?= $selectedVersion ?></th><th><?= cream_e($baseLabel) ?></th></tr></thead>
            <tbody>
            <?php foreach ($fields as $field): ?>
              <tr class="<?= !empty($field['changed']) ? 'is-changed' : '' ?>">
                <th scope="row"><?= cream_e((string) ($field['label'] ?? '')) ?></th>
                <td><?= cream_e((string) ($field['old'] ?? '')) ?></td>
                <td><?= cream_e((string) ($field['new'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="revision-body-head">
          <div>
            <h3>正文差异</h3>
            <p>红色为 v<?= $selectedVersion ?>、绿色为 <?= cream_e($baseLabel) ?>；未变化的行保持灰色。</p>
          </div>
          <form method="post" action="/admin/posts/<?= $postId ?>/revisions/<?= $selectedVersion ?>/restore" class="revision-restore" onsubmit="return confirm('恢复会覆盖当前工作稿，线上文章不会改变。继续？')">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <button class="admin-button" type="submit">恢复为工作稿</button>
          </form>
        </div>

        <?php if (!$hasChanges): ?>
          <div class="revision-same">v<?= $selectedVersion ?> 与 <?= cream_e($baseLabel) ?> 的正文一致，没有可显示的差异。</div>
        <?php else: ?>
          <div class="revision-diff" role="region" aria-label="正文差异">
            <table>
              <tbody>
              <?php foreach ($diff as $row): ?>
                <?php $type = (string) ($row['type'] ?? 'same'); ?>
                <?php if ($type === 'info'): ?>
                  <tr class="diff-info"><td colspan="2"><?= cream_e((string) ($row['text'] ?? '')) ?></td></tr>
                  <?php continue; ?>
                <?php endif; ?>
                <tr class="diff-<?= cream_e($type) ?>">
                  <td class="diff-sign" aria-hidden="true"><?= $type === 'add' ? '+' : ($type === 'remove' ? '−' : ' ') ?></td>
                  <td><code><?= cream_e((string) ($row['text'] ?? '') === '' ? ' ' : (string) ($row['text'] ?? '')) ?></code></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <details class="revision-raw">
          <summary>查看 v<?= $selectedVersion ?> 全文</summary>
          <pre><?= cream_e($selectedText) ?></pre>
        </details>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>
</section>
