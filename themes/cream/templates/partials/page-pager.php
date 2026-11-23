<?php
$pager_ajax = (bool) ($pager_ajax ?? false);
$pager_link_attrs = $pager_ajax ? ' data-ajax-link data-ajax-target="#admin-main" data-ajax-silent' : '';
$pagerPage = max(1, (int) ($pager_page ?? 1));
$pagerPages = max(1, (int) ($pager_pages ?? 1));
$pagerTotal = max(0, (int) ($pager_total ?? 0));
$pagerPrev = is_string($pager_prev ?? null) ? $pager_prev : null;
$pagerNext = is_string($pager_next ?? null) ? $pager_next : null;
$pagerLinks = is_array($pager_links ?? null) ? $pager_links : [];
$pagerLabel = (string) ($pager_label ?? '列表分页');
?>
<?php if ($pagerPages > 1): ?>
<nav class="page-pager" aria-label="<?= cream_e($pagerLabel) ?>">
  <?php if ($pagerPrev !== null): ?>
  <a class="page-pager-step"<?= $pager_link_attrs ?> href="<?= cream_e($pagerPrev) ?>" rel="prev"><?= cream_icon('arrow-left', 'icon-xs') ?> 上一页</a>
  <?php else: ?>
  <span class="page-pager-step is-disabled"><?= cream_icon('arrow-left', 'icon-xs') ?> 上一页</span>
  <?php endif; ?>
  <div class="page-pager-numbers">
  <?php foreach ($pagerLinks as $pagerLink): ?>
    <?php $pagerNumber = $pagerLink['number'] ?? null; ?>
    <?php if ($pagerNumber === null): ?>
    <span class="page-pager-gap" aria-hidden="true">…</span>
    <?php elseif ((int) $pagerNumber === $pagerPage): ?>
    <span class="page-pager-number is-current" aria-current="page"><?= (int) $pagerNumber ?></span>
    <?php else: ?>
    <a class="page-pager-number"<?= $pager_link_attrs ?> href="<?= cream_e((string) ($pagerLink['url'] ?? '#')) ?>"><?= (int) $pagerNumber ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
  <?php if ($pagerNext !== null): ?>
  <a class="page-pager-step"<?= $pager_link_attrs ?> href="<?= cream_e($pagerNext) ?>" rel="next">下一页 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
  <?php else: ?>
  <span class="page-pager-step is-disabled">下一页 <?= cream_icon('arrow-right', 'icon-xs') ?></span>
  <?php endif; ?>
</nav>
<p class="page-pager-meta">第 <?= $pagerPage ?> / <?= $pagerPages ?> 页<?php if ($pagerTotal > 0): ?> · 共 <?= number_format($pagerTotal) ?> 条<?php endif; ?></p>
<?php endif; ?>
