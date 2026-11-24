<?php
$expressionCatalog = cream_expression_catalog();
$sectionKeys = array_keys($expressionCatalog);
$firstSection = (string) ($sectionKeys[0] ?? 'emoji');
?>
<div class="field comment-body-field">
  <div class="comment-body-head">
    <label for="comment-body">留言</label>
    <button class="comment-emoji-trigger" type="button" data-comment-emoji-trigger aria-expanded="false" aria-controls="comment-emoji-panel">
      <span aria-hidden="true">☺</span>
      <span>贴表情</span>
    </button>
  </div>
  <textarea id="comment-body" name="body" rows="4" maxlength="1000" placeholder="写下想说的话，最多 1000 字。" aria-invalid="<?= $bodyInvalid ? 'true' : 'false' ?>"><?= cream_e($bodyValue) ?></textarea>

  <section class="comment-emoji-panel" id="comment-emoji-panel" data-comment-emoji-panel aria-label="表情面板" hidden>
    <div class="comment-emoji-head">
      <div>
        <strong>挑一个表情</strong>
        <small>点一下，插到光标处</small>
      </div>
      <button class="comment-emoji-close" type="button" data-comment-emoji-close aria-label="关闭表情面板"><?= cream_icon('close', 'icon-xs') ?></button>
    </div>

    <div class="comment-emoji-recent" data-comment-emoji-recent hidden>
      <span>最近</span>
      <div class="comment-emoji-recent-list" data-comment-emoji-recent-list></div>
    </div>

    <div class="comment-emoji-sections" role="tablist" aria-label="表情大类">
      <?php foreach ($expressionCatalog as $sectionKey => $section): ?>
      <button class="comment-emoji-section<?= $sectionKey === $firstSection ? ' is-active' : '' ?>" type="button" role="tab" id="comment-emoji-section-tab-<?= cream_e((string) $sectionKey) ?>" aria-controls="comment-emoji-section-<?= cream_e((string) $sectionKey) ?>" aria-selected="<?= $sectionKey === $firstSection ? 'true' : 'false' ?>" tabindex="<?= $sectionKey === $firstSection ? '0' : '-1' ?>" data-comment-expression-section="<?= cream_e((string) $sectionKey) ?>">
        <span><?= cream_e((string) $section['label']) ?></span>
        <small><?= cream_e((string) $section['short']) ?></small>
      </button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($expressionCatalog as $sectionKey => $section): ?>
    <div class="comment-emoji-section-panel" role="tabpanel" id="comment-emoji-section-<?= cream_e((string) $sectionKey) ?>" aria-labelledby="comment-emoji-section-tab-<?= cream_e((string) $sectionKey) ?>" data-comment-expression-panel="<?= cream_e((string) $sectionKey) ?>"<?= $sectionKey === $firstSection ? '' : ' hidden' ?>>
      <div class="comment-emoji-tabs" role="tablist" aria-label="<?= cream_e((string) $section['label']) ?> 小类">
        <?php foreach (($section['groups'] ?? []) as $groupKey => $group): $groupActive = $groupKey === array_key_first($section['groups'] ?? []); ?>
        <button class="comment-emoji-tab<?= $groupActive ? ' is-active' : '' ?>" type="button" role="tab" id="comment-emoji-tab-<?= cream_e((string) $sectionKey) ?>-<?= cream_e((string) $groupKey) ?>" aria-controls="comment-emoji-group-<?= cream_e((string) $sectionKey) ?>-<?= cream_e((string) $groupKey) ?>" aria-selected="<?= $groupActive ? 'true' : 'false' ?>" tabindex="<?= $groupActive ? '0' : '-1' ?>" data-comment-emoji-tab="<?= cream_e((string) $groupKey) ?>" data-comment-emoji-section="<?= cream_e((string) $sectionKey) ?>"><?= cream_e((string) $group['label']) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="comment-emoji-groups">
        <?php foreach (($section['groups'] ?? []) as $groupKey => $group): $groupActive = $groupKey === array_key_first($section['groups'] ?? []); ?>
        <div class="comment-emoji-group<?= $section['kind'] === 'emoji' ? ' is-emoji' : '' ?><?= $section['kind'] === 'text' ? ' is-text' : '' ?><?= $section['kind'] === 'sticker' ? ' is-sticker' : '' ?>" role="tabpanel" id="comment-emoji-group-<?= cream_e((string) $sectionKey) ?>-<?= cream_e((string) $groupKey) ?>" aria-labelledby="comment-emoji-tab-<?= cream_e((string) $sectionKey) ?>-<?= cream_e((string) $groupKey) ?>" data-comment-emoji-group="<?= cream_e((string) $groupKey) ?>" data-comment-emoji-section="<?= cream_e((string) $sectionKey) ?>"<?= $groupActive ? '' : ' hidden' ?>>
          <div class="comment-emoji-group-head">
            <strong><?= cream_e((string) $group['label']) ?></strong>
            <span><?= cream_e((string) $group['hint']) ?></span>
          </div>
          <div class="comment-emoji-grid">
            <?php foreach (($group['items'] ?? []) as $item): ?>
              <?php if ($section['kind'] === 'sticker'): ?>
              <button class="comment-emoji-item is-sticker" type="button" data-comment-emoji="[贴图:<?= cream_e((string) $item['name']) ?>]" data-comment-emoji-name="<?= cream_e((string) $item['name']) ?>" data-comment-emoji-group="<?= cream_e((string) $group['label']) ?>" data-comment-emoji-kind="sticker" data-comment-emoji-sticker="<?= cream_e((string) $item['slug']) ?>" aria-label="插入贴图：<?= cream_e((string) $item['name']) ?>" title="<?= cream_e((string) $item['name']) ?>">
                <span class="comment-emoji-thumb"><?= cream_sticker_picture((string) $item['slug'], 72) ?></span>
                <span class="comment-emoji-caption"><?= cream_e((string) $item['name']) ?></span>
              </button>
              <?php else: ?>
              <button class="comment-emoji-item" type="button" data-comment-emoji="<?= cream_e((string) $item['value']) ?>" data-comment-emoji-name="<?= cream_e((string) $item['name']) ?>" data-comment-emoji-group="<?= cream_e((string) $group['label']) ?>" data-comment-emoji-kind="<?= cream_e((string) $section['kind']) ?>" aria-label="插入<?= cream_e((string) $section['label']) ?>：<?= cream_e((string) $item['name']) ?>" title="<?= cream_e((string) $item['name']) ?>"><?= cream_e((string) $item['value']) ?></button>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($section['kind'] === 'sticker'): ?>
      <p class="comment-emoji-credit">贴图会写成 [贴图:名称]，发布后显示为动图。<br><?= cream_e((string) $section['credit']) ?> · <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener noreferrer">许可</a></p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <p class="comment-emoji-notice" data-comment-emoji-notice aria-live="polite" hidden></p>
    <p class="comment-emoji-status sr-only" aria-live="polite" data-comment-emoji-status></p>
  </section>
</div>
