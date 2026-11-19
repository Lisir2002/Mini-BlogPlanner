<?php $error = $error ?? null; ?>
<section class="admin-auth">
  <div class="admin-auth-card">
    <span class="eyebrow"><?= cream_icon('planner', 'icon-sm') ?> MINI-BLOGPLANNER · 私人后台</span>
    <h1>回到日记里。</h1>
    <p class="admin-lead">管理日记、计划与读者留言。密码使用 Argon2id 校验，登录失败会按 IP 节流。</p>
    <?php if ($error !== null): ?>
    <div class="alert bad"><?= cream_e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin/login.html" class="admin-form" data-ajax="off">
      <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
      <div class="field"><label for="username">用户名</label><input id="username" name="username" autocomplete="username" required autofocus></div>
      <div class="field"><label for="password">密码</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="admin-button primary" type="submit">进入后台</button>
    </form>
    <p class="admin-foot"><a href="/"><?= cream_icon('arrow-left', 'icon-xs') ?> 返回站点</a></p>
  </div>
</section>
