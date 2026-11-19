<?php
$error_heading = '这个动作放错了抽屉。';
$error_detail = '地址还在，只是不接受当前的请求方式。';
$error_note = '直接在浏览器地址栏打开这个地址，通常就能回到正常页面。';
$error_icon = 'layers';
$error_steps = ['用浏览器地址栏重新打开', '回到上一级页面'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'回到首页','href'=>'/','icon'=>'arrow-left','primary'=>true],['label'=>'翻翻文章','href'=>'/posts.html','icon'=>'arrow-right']];
require __DIR__ . '/partial.php';
