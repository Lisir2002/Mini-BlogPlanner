<?php
$error_heading = '这一页没有夹在手账里。';
$error_detail = '地址可能写错了，或者它已经被收进别的抽屉。';
$error_note = '';
$error_icon = 'compass';
$error_steps = ['检查地址的拼写', '从文章或标签列表重新进入'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'回到首页','href'=>'/','icon'=>'arrow-left','primary'=>true],['label'=>'翻翻文章','href'=>'/posts.html','icon'=>'arrow-right'],['label'=>'按标签找','href'=>'/tags.html','icon'=>'tag']];
require __DIR__ . '/partial.php';
