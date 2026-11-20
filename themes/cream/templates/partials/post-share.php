<?php
$shareMeta = is_array($share_meta ?? null) ? $share_meta : [];
$shareUrl = (string) ($shareMeta['url'] ?? '');
$shareTitle = (string) ($shareMeta['title'] ?? ($post->title ?? ''));
$shareDescription = (string) ($shareMeta['description'] ?? '');
?>
<section
  class="post-share"
  aria-labelledby="post-share-title"
  data-share-card
  data-share-url="<?= cream_e($shareUrl) ?>"
  data-share-title="<?= cream_e($shareTitle) ?>"
  data-share-description="<?= cream_e($shareDescription) ?>"
>
  <div class="post-share-copy">
    <span class="post-share-kicker"><?= cream_icon('link', 'icon-xs') ?> PASS THIS PAGE ON</span>
    <h2 id="post-share-title">把这一页递出去</h2>
    <p>复制链接，粘贴到聊天或社交平台。</p>
  </div>
  <div class="post-share-tools">
    <div class="post-share-actions" data-share-tools hidden>
      <button class="post-share-button is-primary" type="button" data-share-copy>
        <?= cream_icon('link', 'icon-xs') ?>
        <span data-share-label>复制链接</span>
      </button>
      <button class="post-share-button" type="button" data-share-native hidden>
        <?= cream_icon('share', 'icon-xs') ?>
        系统分享
      </button>
    </div>
    <p class="post-share-status" data-share-status aria-live="polite"></p>
    <noscript><p class="post-share-fallback">脚本未加载，可从浏览器地址栏复制这一页的链接。</p></noscript>
  </div>
  <span class="post-share-stamp" aria-hidden="true">递出</span>
</section>
