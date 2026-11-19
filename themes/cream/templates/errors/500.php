<?php
$error_heading = '手账暂时翻不开。';
$error_detail = '服务遇到了一点问题，这一页暂时没能准备好。';
$error_note = '问题已经记录到服务日志，稍后会自动恢复。';
$error_icon = 'alert';
$error_steps = ['过一会儿再刷新', '如果一直出现，请联系站点维护者'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'回到首页','href'=>'/','icon'=>'arrow-left','primary'=>true],['label'=>'翻翻文章','href'=>'/posts.html','icon'=>'arrow-right']];
require __DIR__ . '/partial.php';
