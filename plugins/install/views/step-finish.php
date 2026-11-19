<?php
$result = $data['result'] ?? [];
$base = (string) ($result['base_url'] ?? '');
?>
<h1>安装完成</h1>
<p class="lead">站点已经写入版本账本。接下来建议按顺序确认入口、权限、健康检查和备份。</p>



<div class="alert ok">已安装 app <?= \Install\View::e($result['app'] ?? '') ?> / schema <?= \Install\View::e($result['schema'] ?? '') ?>。管理员：<?= \Install\View::e($result['admin'] ?? '') ?>。</div>

<h2>下一步命令</h2>
<pre id="commands">curl -I <?= \Install\View::e($base) ?>/install.php
stat -c '%a %n' .env
curl -fsS <?= \Install\View::e($base) ?>/healthz
php bin/version.php --json
php bin/upgrade.php status --json
bin/backup.sh --check --json</pre>
<div class="actions">
<button type="button" class="secondary" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('commands').innerText)">复制命令</button>
<a class="btn" href="<?= \Install\View::e($base) ?>/">打开站点</a>
<a class="btn secondary" href="<?= \Install\View::e($base) ?>/healthz">健康检查</a>
</div>
<p class="note">安装锁已写入 <code>installed.lock</code>；再次访问 <code>/install.php</code> 应为 404。</p>
