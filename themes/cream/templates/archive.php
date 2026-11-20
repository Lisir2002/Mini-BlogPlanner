<?php
$years = is_array($years ?? null) ? $years : [];
$total = (int) ($total ?? 0);
?>
<section class="archive archive-index">
  <header class="archive-head">
    <span class="eyebrow"><?= cream_icon('calendar', 'icon-sm') ?> ARCHIVE</span>
    <h1>按年份翻回去</h1>
    <p>共 <?= number_format($total) ?> 篇，按年与月排成一本可以往回翻的账。</p>
  </header>

<?php if ($years === []): ?>
  <div class="empty-note">
    <span class="empty-icon"><?= cream_icon('calendar') ?></span>
    <h2>归档还是空的</h2>
    <p>等第一篇文章发布后，这里会按年份和月份自动长出来。</p>
  </div>
<?php else: ?>
  <div class="archive-ledger">
<?php foreach ($years as $year): ?>
    <article class="archive-year-card">
      <header class="archive-year-head">
        <div class="archive-year-label">
          <span>YEAR</span>
          <h2><a href="/archive/<?= (int) $year['year'] ?>.html"><?= (int) $year['year'] ?></a></h2>
        </div>
        <span class="archive-year-count"><?= number_format((int) $year['count']) ?> 篇</span>
      </header>
      <div class="archive-month-grid">
<?php foreach ($year['months'] as $month): ?>
        <a class="archive-month-link" href="/archive/<?= (int) $year['year'] ?>/<?= str_pad((string) (int) $month['month'], 2, '0', STR_PAD_LEFT) ?>.html">
          <span class="archive-month-name"><?= (int) $month['month'] ?> 月</span>
          <span class="archive-month-count"><?= number_format((int) $month['count']) ?> 篇</span>
        </a>
<?php endforeach; ?>
      </div>
    </article>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
