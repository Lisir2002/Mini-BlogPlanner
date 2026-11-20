<aside class="post-receipt" data-post-receipt>
  <button
    class="post-receipt-tab"
    type="button"
    data-receipt-tab
    aria-controls="post-receipt-panel"
    aria-expanded="false"
    aria-label="打开读者回执"
    hidden
  >
    <span class="post-receipt-tab-mark" aria-hidden="true">回</span>
    <span class="post-receipt-tab-label">执</span>
    <span class="post-receipt-tab-arrow" aria-hidden="true"><?= cream_icon('arrow-left', 'icon-xs') ?></span>
  </button>
  <div class="post-receipt-panel" id="post-receipt-panel" data-receipt-panel>
    <span class="post-receipt-tape" aria-hidden="true"></span>
    <span class="post-receipt-stamp" aria-hidden="true">读者回执</span>
    <div class="post-receipt-feedback">
      <?php require __DIR__ . '/reactions.php'; ?>
    </div>
    <div class="post-receipt-share">
      <?php require __DIR__ . '/post-share.php'; ?>
    </div>
  </div>
</aside>
