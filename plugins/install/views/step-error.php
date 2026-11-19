<h1><?= \Install\View::e($title ?? '安装中止') ?></h1>
<p class="lead"><?= \Install\View::e($message ?? '安装程序拒绝继续。') ?></p>
<div class="actions"><a class="btn secondary" href="javascript:history.back()">返回</a></div>
