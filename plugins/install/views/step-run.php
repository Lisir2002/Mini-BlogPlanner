<?php
$database = $data['database'] ?? [];
$admin = $data['admin'] ?? [];
$site = $data['site'] ?? [];
$error = $data['error'] ?? null;
?>
<h1>确认并执行</h1>
<p class="lead">执行顺序：跑迁移 → 写站点设置 → 建管理员 → 写 <code>.env</code> → 写 <code>installed.lock</code> → 关闭安装入口。</p>

<?php if ($error !== null): ?>
<div class="alert bad"><?= \Install\View::e($error) ?></div>
<p class="note">迁移已成功记录的部分不会回滚；修复问题后可以重新提交，已完成的迁移会跳过。</p>
<?php endif; ?>

<table>
<tbody>
<tr><th>数据库</th><td><?= \Install\View::e($database['user'] ?? '') ?>@<?= \Install\View::e($database['host'] ?? '') ?>:<?= \Install\View::e($database['port'] ?? '') ?> / <?= \Install\View::e($database['name'] ?? '') ?></td></tr>
<tr><th>管理员</th><td><?= \Install\View::e($admin['username'] ?? '') ?> · <?= \Install\View::e($admin['email'] ?? '') ?></td></tr>
<tr><th>站点</th><td><?= \Install\View::e($site['title'] ?? '') ?> · <?= \Install\View::e($site['base_url'] ?? '') ?></td></tr>
<tr><th>主题</th><td><?= \Install\View::e($site['theme'] ?? 'cream') ?></td></tr>
</tbody>
</table>

<form method="post" action="/install.php?step=run">
<input type="hidden" name="_csrf" value="<?= \Install\View::e($csrf) ?>">
<div class="field" style="margin-top:16px"><label for="db_password">再次输入数据库密码</label><input id="db_password" name="db_password" type="password" autocomplete="current-password" required></div>
<div class="actions"><button type="submit">开始安装</button></div>
</form>
<p class="note">安装过程中不要刷新页面；并发提交会被安装锁拒绝。</p>
