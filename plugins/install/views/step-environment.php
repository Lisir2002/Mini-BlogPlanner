<?php
/** @var array<string, mixed> $environment */
$errors = $environment['errors'] ?? [];
$warnings = $environment['warnings'] ?? [];
$checks = $environment['checks'] ?? [];
?>
<h1>先检查这台机器</h1>
<p class="lead">安装器不会在检查失败时继续。缺什么会直接列出来，不会把错误藏到日志里。</p>

<?php foreach ($errors as $error): ?>
<div class="alert bad"><?= \Install\View::e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($warnings as $warning): ?>
<div class="alert warn"><?= \Install\View::e($warning) ?></div>
<?php endforeach; ?>

<table>
<thead><tr><th>检查项</th><th>结果</th><th>说明</th></tr></thead>
<tbody>
<?php foreach ($checks as $check): ?>
<tr>
<td><?= \Install\View::e($check['label'] ?? '') ?></td>
<td class="<?= !empty($check['ok']) ? 'mark-ok' : 'mark-bad' ?>"><?= !empty($check['ok']) ? '通过' : '阻断' ?></td>
<td><?= \Install\View::e($check['detail'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<form method="post" action="/install.php?step=environment" class="actions">
<input type="hidden" name="_csrf" value="<?= \Install\View::e($csrf) ?>">
<button type="submit" class="secondary">重新检查</button>
<?php if ($errors === []): ?>
<button type="submit">继续</button>
<?php endif; ?>
</form>
<p class="note">如果缺扩展，先按上面的提示安装；如果缺 vendor，执行 <code>composer install --no-dev</code>。</p>
