<?php
$error_heading = '这张便签还需要修改。';
$error_detail = '提交的内容没有通过检查，修正后就能继续。';
$error_note = '已经填写的内容可能没有全部保留，请再确认一次。';
$error_icon = 'pen';
$error_steps = ['检查必填项', '修正格式后重新提交'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'回到后台','href'=>'/admin/posts.html','icon'=>'arrow-left','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-right']];
require __DIR__ . '/partial.php';
