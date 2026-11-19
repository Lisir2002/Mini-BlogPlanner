<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= \Install\View::e($title ?? '安装 · Mini-BlogPlanner') ?></title>
<style>
:root{color-scheme:light;--cream:oklch(.968 .018 90);--paper:oklch(.998 .004 90);--ink:oklch(.245 .010 70);--muted:oklch(.470 .010 70);--line:oklch(.885 .012 85);--coral:oklch(.680 .135 32);--butter:oklch(.905 .090 95);--mint:oklch(.835 .055 160);--danger:oklch(.560 .170 30)}
*{box-sizing:border-box}
body{margin:0;background:var(--cream);color:var(--ink);font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.shell{width:min(920px,calc(100% - 32px));margin:0 auto;padding:28px 0 48px}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:-.01em}
.mark{display:grid;place-items:center;width:34px;height:34px;border:1px solid var(--line);border-radius:12px;background:var(--paper)}
.steps{display:flex;flex-wrap:wrap;gap:6px}
.pill{padding:5px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;color:var(--muted);background:color-mix(in oklch,var(--paper) 80%,var(--cream))}
.pill.on{background:var(--butter);color:var(--ink);border-color:transparent;font-weight:700}
.card{background:var(--paper);border:1px solid var(--line);border-radius:22px;padding:clamp(20px,4vw,36px);box-shadow:3px 3px 0 color-mix(in oklch,var(--ink) 10%,transparent)}
h1{margin:0 0 8px;font-size:clamp(26px,4vw,36px);line-height:1.2;letter-spacing:-.02em}
.lead{margin:0 0 24px;color:var(--muted)}
h2{font-size:18px;margin:24px 0 10px}
.alert{padding:12px 14px;border-radius:12px;margin:0 0 10px;font-size:14px}
.alert.bad{background:color-mix(in oklch,var(--danger) 10%,var(--paper));border:1px solid color-mix(in oklch,var(--danger) 35%,var(--line))}
.alert.warn{background:color-mix(in oklch,var(--butter) 40%,var(--paper));border:1px solid color-mix(in oklch,var(--butter) 70%,var(--line))}
.alert.ok{background:color-mix(in oklch,var(--mint) 30%,var(--paper));border:1px solid color-mix(in oklch,var(--mint) 60%,var(--line))}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
.field{display:grid;gap:6px}
.field label{font-size:13px;font-weight:650}
.field input,.field select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--cream);color:var(--ink);font:inherit}
.field input:focus,.field select:focus{outline:2px solid var(--coral);outline-offset:1px}
.check{display:flex;gap:8px;align-items:center;font-size:13px;color:var(--muted)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.mark-ok{color:oklch(.520 .120 150);font-weight:700}
.mark-bad{color:var(--danger);font-weight:700}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}
button,.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:999px;border:1px solid var(--ink);background:var(--ink);color:var(--paper);font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
button:hover,.btn:hover{transform:translateY(-1px)}
button.secondary,.btn.secondary{background:var(--paper);color:var(--ink)}
code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
pre{overflow:auto;padding:14px;border-radius:12px;background:color-mix(in oklch,var(--cream) 70%,var(--paper));border:1px solid var(--line);font-size:13px;line-height:1.6}
.note{font-size:13px;color:var(--muted);margin-top:12px}
.footer{margin-top:18px;text-align:center;font-size:12px;color:var(--muted)}
@media(max-width:560px){.card{border-radius:16px;box-shadow:none}.top{align-items:flex-start;flex-direction:column}.steps{width:100%}}
@media(prefers-reduced-motion:no-preference){.card{animation:in .36s cubic-bezier(.22,1,.36,1)}@keyframes in{from{opacity:0;transform:translateY(8px)}}}
</style>
</head>
<body>
<div class="shell">
<header class="top">
<div class="brand">
<span class="mark" aria-hidden="true">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 4.5h9.5A2.5 2.5 0 0 1 17 7v12.5H7.5A2.5 2.5 0 0 1 5 17V4.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M8 8h6M8 12h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
</span>
<span>Mini-BlogPlanner · 安装</span>
</div>
<nav class="steps" aria-label="安装步骤">
<?php
$labels = ['environment' => '1 环境', 'database' => '2 数据库', 'admin' => '3 管理员', 'site' => '4 站点', 'run' => '5 执行', 'finish' => '6 完成'];
$current = $step ?? '';
foreach ($labels as $key => $label) {
    echo '<span class="pill ' . ($key === $current ? 'on' : '') . '">' . \Install\View::e($label) . '</span>';
}
?>
</nav>
</header>
<main class="card">
<?= $content ?>
</main>
<footer class="footer">v<?= \Install\View::e($app ?? '') ?> · 安装完成后此入口会关闭</footer>
</div>
</body>
</html>
