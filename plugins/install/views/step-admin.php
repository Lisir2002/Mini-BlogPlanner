<?php
$values = $data['values'] ?? [];
$errors = $data['errors'] ?? [];
?>
<h1>创建管理员</h1>
<p class="lead">密码只在本步内存中处理，立即用 Argon2id 哈希；不会写进日志，也不会明文进数据库。</p>

<?php foreach ($errors as $error): ?>
<div class="alert bad"><?= \Install\View::e($error) ?></div>
<?php endforeach; ?>

<form method="post" action="/install.php?step=admin">
<input type="hidden" name="_csrf" value="<?= \Install\View::e($csrf) ?>">
<div class="grid">
<div class="field"><label for="username">用户名</label><input id="username" name="username" value="<?= \Install\View::e($values['username'] ?? '') ?>" autocomplete="username" required></div>
<div class="field"><label for="email">邮箱</label><input id="email" name="email" type="email" value="<?= \Install\View::e($values['email'] ?? '') ?>" autocomplete="email" required></div>
<div class="field"><label for="password">密码</label><input id="password" name="password" type="password" autocomplete="new-password" required></div>
<div class="field"><label for="password_confirm">确认密码</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required></div>
</div>
<p class="note">至少 12 位，必须同时包含字母和数字。</p>
<div class="actions"><button type="submit">继续</button></div>
</form>
