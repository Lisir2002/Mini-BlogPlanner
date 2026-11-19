<?php
$error_heading = '这一页已经过期了。';
$error_detail = '安全校验没有通过，或者停留太久，原来的提交已经失效。';
$error_note = '刷新页面后会拿到新的校验信息。';
$error_icon = 'clock';
$error_steps = ['刷新当前页面', '重新填写并提交'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'重新登录','href'=>'/admin/login.html','icon'=>'lock','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-left']];
require __DIR__ . '/partial.php';
