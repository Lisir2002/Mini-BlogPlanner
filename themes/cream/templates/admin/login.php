<?php $error = $error ?? null; ?>
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
        <div class="admin-captcha">
          <div class="admin-captcha-head">
            <span>03 / GRAPHIC CHECK</span>
            <span>站内生成 · 一次性</span>
          </div>
          <div class="admin-captcha-answer">
            <label for="captcha">输入图上的 5 个字符</label>
            <div class="admin-captcha-field">
              <input id="captcha" name="captcha" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="5" pattern="[A-Za-z0-9]{5}" placeholder="5 位字符" required>
              <button class="admin-captcha-refresh" type="button" data-captcha-refresh aria-label="点击刷新图形验证码" title="点击换一张">
                <img id="admin-captcha-image" src="/admin/captcha" width="160" height="50" alt="图形验证码，点击换一张" draggable="false">
              </button>
            </div>
          </div>
          <p class="admin-captcha-note">看不清就点输入框右侧的图片；验证码 5 分钟内有效，只能用一次。</p>
        </div>
        <script>
        (function () {
          var image = document.getElementById('admin-captcha-image');
          var refresh = document.querySelector('[data-captcha-refresh]');
          if (!image || !refresh) return;
          function reloadCaptcha() {
            image.src = '/admin/captcha?t=' + Date.now();
            var answer = document.getElementById('captcha');
            if (answer) {
              answer.value = '';
              answer.focus();
            }
          }
          refresh.addEventListener('click', reloadCaptcha);
        })();
        </script>
        <button class="admin-button primary full" type="submit"><?= cream_icon('lock', 'icon-xs') ?> 进入后台</button>
      </form>
      <p class="admin-foot"><a href="/"><?= cream_icon('arrow-left', 'icon-xs') ?> 返回站点</a></p>
    </div>
  </div>
</section>
