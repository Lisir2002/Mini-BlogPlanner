<?php
$error_heading = '手账遇到了一点问题。';
$error_detail = '这个请求没有顺利完成。';
$error_note = '可以回到首页，或者稍后再试一次。';
$error_icon = 'book';
$error_steps = ['检查地址是否完整', '稍后再试一次'];
$isAdmin = (($error_context ?? 'public') === 'admin');
$error_actions = $isAdmin
    ? [['label' => '回到后台', 'href' => '/admin/posts.html', 'icon' => 'arrow-left', 'primary' => true], ['label' => '回到首页', 'href' => '/', 'icon' => 'arrow-right']]
    : [['label' => '回到首页', 'href' => '/', 'icon' => 'arrow-left', 'primary' => true], ['label' => '翻翻文章', 'href' => '/posts.html', 'icon' => 'arrow-right']];
require __DIR__ . '/partial.php';
