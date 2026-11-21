<?php
$status = (int) ($error_status ?? 404);
$context = (string) ($error_context ?? 'public');
$isAdmin = $context === 'admin';
$code = (string) $status;
$heading = (string) ($error_heading ?? '手账遇到了一点问题。');
$detail = (string) ($error_detail ?? '稍后再试一次，或者回到首页重新开始。');
$note = (string) ($error_note ?? '');
$icon = (string) ($error_icon ?? 'book');
$steps = is_array($error_steps ?? null) ? $error_steps : [];
$path = (string) ($error_path ?? '');
$reason = (string) ($error_reason ?? '');
$actions = is_array($error_actions ?? null) ? $error_actions : [];
?>
<section class="error-page error-page--<?= cream_e($code) ?>" aria-labelledby="error-title">
  <div class="error-card">
    <span class="error-tape" aria-hidden="true"></span>
    <div class="error-top">
      <span class="error-code" aria-hidden="true"><?= cream_e($code) ?></span>
      <span class="error-emblem" aria-hidden="true"><?= cream_icon($icon, 'error-icon') ?></span>
    </div>
    <span class="eyebrow"><?= cream_icon('planner', 'icon-sm') ?> MINI-BLOGPLANNER · <?= $isAdmin ? '后台错误' : '站点错误' ?></span>
    <p class="error-heading" id="error-title"><?= cream_e($heading) ?></p>
    <p class="error-detail"><?= cream_e($detail) ?></p>

    <?php if ($note !== ''): ?>
    <p class="error-note"><?= cream_e($note) ?></p>
    <?php endif; ?>

    <?php if ($reason !== ''): ?>
    <div class="error-reason">
      <span>具体情况</span>
      <strong><?= cream_e($reason) ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($path !== ''): ?>
    <code class="error-path"><?= cream_e($path) ?></code>
    <?php endif; ?>

    <?php if ($steps !== []): ?>
    <div class="error-steps">
      <span class="error-steps-label">可以试试</span>
      <ul>
        <?php foreach ($steps as $step): ?>
        <li><?= cream_e((string) $step) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="error-actions">
      <?php foreach ($actions as $action): ?>
      <a class="button <?= ($action['primary'] ?? false) ? 'button-primary' : 'button-ghost' ?>" href="<?= cream_e((string) ($action['href'] ?? '/')) ?>">
        <?= cream_icon((string) ($action['icon'] ?? 'arrow-right'), 'icon-sm') ?> <?= cream_e((string) ($action['label'] ?? '继续')) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
