<?php $error = $error ?? null; $turnstileEnabled = $turnstileEnabled ?? false; $turnstileSiteKey = $turnstileSiteKey ?? ''; ?>
<section class="admin-auth">
  <div class="admin-auth-layout">
    <aside class="admin-auth-note">
      <span class="admin-note-tape" aria-hidden="true"></span>
      <span class="eyebrow"><?= cream_icon('lock', 'icon-sm') ?> PRIVATE DESK · 01</span>
      <h2>后台不是前门。</h2>
      <p class="admin-note-lead">这里收着还没写完的草稿、待审的留言，和每一张贴好的便签。</p>
      <ul class="admin-note-list">
        <li>
          <span class="admin-note-icon"><?= cream_icon('lock', 'icon-sm') ?></span>
          <span><strong>Argon2id</strong>密码校验</span>
        </li>
        <li>
          <span class="admin-note-icon"><?= cream_icon('shield', 'icon-sm') ?></span>
          <span><strong>IP 节流</strong>连续失败会暂缓</span>
        </li>
        <li>
          <span class="admin-note-icon"><?= cream_icon('planner', 'icon-sm') ?></span>
          <span><strong>单人后台</strong>只管理这本日记</span>
        </li>
      </ul>
      <span class="admin-note-stamp">MINI-BLOGPLANNER<br>BACKSTAGE · 01</span>
    </aside>

    <div class="admin-auth-card">
      <span class="admin-auth-card-index" aria-hidden="true">AUTH / 01</span>
      <div class="admin-auth-head">
        <span class="eyebrow"><?= cream_icon('lock', 'icon-sm') ?> 私人入口</span>
        <h1>回到日记里。</h1>
        <p class="admin-lead">管理日记、计划与读者留言。密码使用 Argon2id 校验，登录失败会按 IP 节流。</p>
      </div>
      <?php if ($error !== null): ?>
      <div class="alert bad"><?= cream_e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="/admin/login.html" class="admin-form admin-login-form" data-ajax="off">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <div class="tape-field">
          <div class="tape-field-head">
            <label for="username">用户名</label>
            <span>01 / ACCOUNT</span>
          </div>
          <div class="tape-input">
            <input id="username" name="username" autocomplete="username" placeholder="输入管理员账号" required autofocus>
          </div>
        </div>
        <div class="tape-field">
          <div class="tape-field-head">
            <label for="password">密码</label>
            <span>02 / PASSWORD</span>
          </div>
          <div class="tape-input">
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="输入密码" required>
          </div>
        </div>
        <?php if ($turnstileEnabled): ?>
        <div class="admin-turnstile">
          <div class="admin-turnstile-head">
            <span>03 / HUMAN CHECK</span>
            <span>Cloudflare Turnstile</span>
          </div>
          <div class="cf-turnstile" data-sitekey="<?= cream_e($turnstileSiteKey) ?>" data-action="login" data-theme="light" data-size="flexible" data-language="zh-cn"></div>
          <p class="admin-turnstile-note">只确认这次登录不是自动脚本，不读取密码或账号。</p>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <?php endif; ?>
        <button class="admin-button primary full" type="submit"><?= cream_icon('lock', 'icon-xs') ?> 进入后台</button>
      </form>
      <p class="admin-foot"><a href="/"><?= cream_icon('arrow-left', 'icon-xs') ?> 返回站点</a></p>
    </div>
  </div>
</section>
