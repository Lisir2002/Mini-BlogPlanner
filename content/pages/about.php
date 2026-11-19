<?php

declare(strict_types=1);

/**
 * 独立页面示例。该文件由站长维护，可访问 $context（PageContext）。
 * 返回值支持数组、字符串、PageResult 或 callable。
 */
use Core\Page\PageContext;

return static function (PageContext $context): array {
    $writtenAt = date('Y.m.d');
    $bookIcon = cream_icon('book');
    $codeIcon = cream_icon('layers');
    $paperIcon = cream_icon('leaf');

    return [
        'title' => '关于这本手账',
        'template' => 'page',
        'styles' => ['pages/about.css'],
        'content' => <<<HTML
<section class="about-sheet">
  <header class="about-head">
    <span class="about-kicker">ABOUT THIS JOURNAL</span>
    <h1>写给未来翻页的人。</h1>
    <p>这里不追热点，只把写代码时想明白的事、日子里值得记住的片刻，一页一页贴好。</p>
  </header>
  <div class="about-grid">
    <article class="about-card">
      <span class="about-icon">{$bookIcon}</span>
      <h2>内容为主</h2>
      <p>文章、标签、系列和独立页面都围绕“好读”设计。没有弹窗，没有命令面板，也没有追着你跑的推荐。</p>
    </article>
    <article class="about-card">
      <span class="about-icon">{$codeIcon}</span>
      <h2>代码要轻</h2>
      <p>微内核只做路由、契约和版本；功能以插件接入，主题只负责呈现。单文件保持短小，职责不混在一起。</p>
    </article>
    <article class="about-card">
      <span class="about-icon">{$paperIcon}</span>
      <h2>手账质感</h2>
      <p>奶油米色、黑灰白主调，少量珊瑚与奶油黄点缀。图标全部自绘，不引入图标字体。</p>
    </article>
  </div>
  <div class="about-note">
    <p>这个页面就是独立页面运行时的一个例子：它有自己的 PHP、自己的 CSS，不经过内核拼 HTML。</p>
    <p class="about-date">写于 {$writtenAt}</p>
  </div>
</section>
HTML,
    ];
};
