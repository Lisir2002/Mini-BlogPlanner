<?php
$error_heading = '手账正在整理。';
$error_detail = '站点正在维护或暂时不可用，稍后会重新开放。';
$error_note = '维护期间数据不会丢失，可以过几分钟再来。';
$error_icon = 'spark';
$error_steps = ['稍后刷新', '关注站点公告'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'稍后刷新','href'=>($error_path ?? '') !== '' ? $error_path : '/','icon'=>'arrow-right','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-left']];
require __DIR__ . '/partial.php';
