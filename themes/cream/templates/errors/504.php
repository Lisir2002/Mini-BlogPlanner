<?php
$error_heading = '等回信等得太久了。';
$error_detail = '上游服务响应超时，这一页没有在限定时间内返回。';
$error_note = '稍后再试，通常会比第一次更快。';
$error_icon = 'clock';
$error_steps = ['稍后刷新', '回到首页'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'稍后刷新','href'=>($error_path ?? '') !== '' ? $error_path : '/','icon'=>'arrow-right','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-left']];
require __DIR__ . '/partial.php';
