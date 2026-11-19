<?php
$error_heading = '这张便签没写完整。';
$error_detail = '请求里缺少必要的信息，或者某一项格式不太对。';
$error_note = '链接可能被截断，也可能少带了一个参数。';
$error_icon = 'pen';
$error_steps = ['检查地址是否完整', '确认参数没有多余符号'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'回到首页','href'=>'/','icon'=>'arrow-left','primary'=>true],['label'=>'翻翻文章','href'=>'/posts.html','icon'=>'arrow-right']];
require __DIR__ . '/partial.php';
