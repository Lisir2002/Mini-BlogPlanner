<?php
$code = (string) ($error_code ?? '404');
$heading = (string) ($error_heading ?? '这一页没有夹在手账里。');
$detail = (string) ($error_detail ?? '地址可能写错了，或者它已经被收进别的抽屉。');
$path = (string) ($error_path ?? '');
?>
<section class="error-page">
  <div class="error-card">
    <span class="error-code" aria-hidden="true"><?= cream_e($code) ?></span>
    <span class="eyebrow"><?= cream_icon('planner', 'icon-sm') ?> MINI-BLOGPLANNER</span>
    <p class="error-heading"><?= cream_e($heading) ?></p>
    <p><?= cream_e($detail) ?></p>
    <?php if ($path !== ''): ?>
    <code class="error-path"><?= cream_e($path) ?></code>
    <?php endif; ?>
    <div class="error-actions">
      <a class="button button-primary" href="/"><?= cream_icon('arrow-left', 'icon-sm') ?> 回到首页</a>
      <a class="button button-ghost" href="/posts.html">翻翻文章</a>
      <a class="button button-ghost" href="/tags.html">按标签找</a>
    </div>
  </div>
</section>
