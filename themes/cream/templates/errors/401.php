<?php
$error_heading = '先确认一下你的身份。';
$error_detail = '这一页需要登录后才能查看，当前还没有有效的登录状态。';
$error_note = '登录后回到原地址，就能继续打开这一页。';
$error_icon = 'lock';
$error_steps = ['打开后台登录页', '登录后再访问这一页'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'去登录','href'=>'/admin/login.html','icon'=>'arrow-right','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-left']];
require __DIR__ . '/partial.php';
