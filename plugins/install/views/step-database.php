<?php
$values = $data['values'];
$errors = $data['errors'] ?? [];
$warnings = $data['warnings'] ?? [];
?>
<h1>连接数据库</h1>
<p class="lead">建议使用只拥有该库权限的专用账号，不要用 root。安装器会把连接信息写入 <code>.env</code>，权限 0600。</p>

<?php foreach ($errors as $error): ?>
<div class="alert bad"><?= \Install\View::e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($warnings as $warning): ?>
<div class="alert warn"><?= \Install\View::e($warning) ?></div>
<?php endforeach; ?>

<form method="post" action="/install.php?step=database">
<input type="hidden" name="_csrf" value="<?= \Install\View::e($csrf) ?>">
<div class="grid">
<div class="field"><label for="db_host">主机</label><input id="db_host" name="db_host" value="<?= \Install\View::e($values['host'] ?? '127.0.0.1') ?>" required></div>
<div class="field"><label for="db_port">端口</label><input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= \Install\View::e($values['port'] ?? 3306) ?>" required></div>
<div class="field"><label for="db_name">数据库名</label><input id="db_name" name="db_name" value="<?= \Install\View::e($values['name'] ?? 'cream_journal') ?>" required></div>
<div class="field"><label for="db_user">数据库用户</label><input id="db_user" name="db_user" value="<?= \Install\View::e($values['user'] ?? '') ?>" autocomplete="username" required></div>
<div class="field"><label for="db_password">数据库密码</label><input id="db_password" name="db_password" type="password" autocomplete="current-password" value=""></div>
</div>
<label class="check"><input type="checkbox" name="db_create" value="1" <?= !empty($values['create']) ? 'checked' : '' ?>> 数据库不存在时允许创建</label>
<div class="actions"><button type="submit">测试并继续</button></div>
</form>
<p class="note">MySQL 目标版本 9.7.4；字符集 utf8mb4；排序规则 utf8mb4_0900_ai_ci。</p>
