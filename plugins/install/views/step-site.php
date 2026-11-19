<?php
$values = $data['values'] ?? [];
$errors = $data['errors'] ?? [];
$zones = ['Asia/Shanghai', 'UTC', 'Asia/Tokyo', 'Asia/Hong_Kong', 'America/New_York', 'Europe/London'];
?>
<h1>站点信息</h1>
<p class="lead">这些值会写入 <code>site_settings</code>，同时决定 <code>.env</code> 的站点地址和时区。</p>

<?php foreach ($errors as $error): ?>
<div class="alert bad"><?= \Install\View::e($error) ?></div>
<?php endforeach; ?>

<form method="post" action="/install.php?step=site">
<input type="hidden" name="_csrf" value="<?= \Install\View::e($csrf) ?>">
<div class="grid">
<div class="field"><label for="site_title">站点标题</label><input id="site_title" name="site_title" value="<?= \Install\View::e($values['title'] ?? '日记Planner') ?>" maxlength="80" required></div>
<div class="field"><label for="base_url">Base URL</label><input id="base_url" name="base_url" type="url" value="<?= \Install\View::e($values['base_url'] ?? '') ?>" placeholder="https://example.com" required></div>
<div class="field"><label for="timezone">时区</label>
<select id="timezone" name="timezone">
<?php foreach ($zones as $zone): ?>
<option value="<?= \Install\View::e($zone) ?>" <?= ($values['timezone'] ?? '') === $zone ? 'selected' : '' ?>><?= \Install\View::e($zone) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="field"><label for="language">语言</label>
<select id="language" name="language">
<option value="zh-CN" <?= ($values['language'] ?? '') === 'zh-CN' ? 'selected' : '' ?>>简体中文</option>
<option value="en-US" <?= ($values['language'] ?? '') === 'en-US' ? 'selected' : '' ?>>English</option>
</select>
</div>
<div class="field"><label for="theme">默认主题</label>
<select id="theme" name="theme"><option value="cream" selected>cream（奶油手账）</option></select>
</div>
</div>
<div class="field" style="margin-top:14px"><label for="site_description">站点描述</label><input id="site_description" name="site_description" value="<?= \Install\View::e($values['description'] ?? '') ?>" maxlength="200"></div>
<div class="actions"><button type="submit">继续</button></div>
</form>
