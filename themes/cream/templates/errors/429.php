<?php
$error_heading = '翻页翻得太快了。';
$error_detail = '短时间里请求太多，先停下来歇一会儿。';
$error_note = '等待几十秒后再试，通常就会恢复。';
$error_icon = 'clock';
$error_steps = ['稍等片刻', '减少连续刷新'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = [['label'=>'再试一次','href'=>($error_path ?? '') !== '' ? $error_path : '/','icon'=>'arrow-right','primary'=>true],['label'=>'回到首页','href'=>'/','icon'=>'arrow-left']];
require __DIR__ . '/partial.php';
