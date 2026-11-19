<?php
$error_heading = '这页被锁进抽屉了。';
$error_detail = '当前账号没有查看这一页的权限。';
$error_note = '如果这是你自己的站点，请换用有权限的账号。';
$error_icon = 'shield';
$error_steps = ['换用有权限的账号', '回到可以访问的页面'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'回到首页','href'=>'/','icon'=>'arrow-left','primary'=>true],['label'=>'翻翻文章','href'=>'/posts.html','icon'=>'arrow-right']];
require __DIR__ . '/partial.php';
