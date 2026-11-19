<?php
$error_heading = '中间那一页卡住了。';
$error_detail = '上游服务没有正常回应，页面暂时取不到内容。';
$error_note = '这通常是短时故障，不需要修改地址。';
$error_icon = 'alert';
$error_steps = ['稍后刷新', '回到首页'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'稍后刷新','href'=>($error_path ?? '') !== '' ? $error_path : '/','icon'=>'arrow-right','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-left']];
require __DIR__ . '/partial.php';
