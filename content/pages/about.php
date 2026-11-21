<?php

declare(strict_types=1);

/**
 * 独立页面的内容由站长维护，可通过 $context 读取站点版本。
 */
use Core\Page\PageContext;

return static function (PageContext $context): array {
    $writtenAt = date('Y.m.d');
    $appVersion = 'v' . $context->version->app();
    $plannerIcon = cream_icon('planner');
    $penIcon = cream_icon('pen');
    $leafIcon = cream_icon('leaf');
    $rssIcon = cream_icon('rss', 'icon-xs');
    $checkIcon = cream_icon('check', 'icon-xs');
    $closeIcon = cream_icon('close', 'icon-xs');

    return [
        'title' => '关于这本手账',
        'template' => 'page',
        'styles' => ['pages/about.css'],
        'content' => <<<HTML
<section class="about-sheet">
  <header class="about-hero">
    <div class="about-hero-copy">
      <span class="about-kicker">ABOUT THIS JOURNAL · 00</span>
      <h2 class="about-display">写给未来翻页的人。</h2>
      <p class="about-lead">这里不追热点，只把写代码时想明白的事、日子里值得记住的片刻，一页一页贴好。你来的时候，可以慢慢翻。</p>
      <div class="about-actions">
        <a class="about-button" href="/posts.html">从第一页读起 <span aria-hidden="true">→</span></a>
        <a class="about-text-link" href="/archive.html">按月份翻</a>
        <a class="about-text-link" href="/tags.html">按标签找</a>
      </div>
      <div class="about-meta" aria-label="页面信息">
        <span>PAGE 00</span>
        <span>EDITION {$appVersion}</span>
        <span>LAST UPDATE {$writtenAt}</span>
      </div>
    </div>

    <aside class="about-now">
      <span class="about-now-tape" aria-hidden="true"></span>
      <span class="about-now-eyebrow">NOW / {$writtenAt}</span>
      <h2>现在这本手账</h2>
      <dl class="about-now-list">
        <div>
          <dt>正在写</dt>
          <dd>代码与日子的边角</dd>
        </div>
        <div>
          <dt>正在修</dt>
          <dd>搜索、升级与纸面细节</dd>
        </div>
        <div>
          <dt>正在学</dt>
          <dd>把复杂的事讲简单</dd>
        </div>
      </dl>
      <span class="about-now-stamp">{$appVersion}</span>
    </aside>
  </header>

  <section class="about-section" id="about-why">
    <header class="about-section-head">
      <span class="about-section-no">01</span>
      <div>
        <span class="about-section-kicker">WHY THIS PAGE EXISTS</span>
        <h2>为什么写这里</h2>
      </div>
      <p>给未来的自己留一份索引，也给偶然翻到这里的人一盏小灯。</p>
    </header>

    <div class="about-manifesto">
      <blockquote>
        <span class="about-quote-mark" aria-hidden="true">“</span>
        <span class="about-manifesto-kicker">MANIFESTO / 手账宣言</span>
        <p>把代码和日子贴进同一本手账。不是为了证明写了多少，而是让每一页都能被重新读懂。</p>
        <footer class="about-manifesto-note">读慢一点，也算读完了。</footer>
      </blockquote>

      <div class="about-principles">
        <article class="about-principle">
          <span class="about-principle-no">01</span>
          <div>
            <h3>内容先于形式</h3>
            <p>排版为阅读让路，动效为内容让路。没有弹窗、没有自动播放，也没有追着你跑的推荐。</p>
          </div>
        </article>
        <article class="about-principle">
          <span class="about-principle-no">02</span>
          <div>
            <h3>结构经得起回看</h3>
            <p>版本、备份、归档和发布清单都有据可查。写得慢一点，也要让以后能找得到。</p>
          </div>
        </article>
        <article class="about-principle">
          <span class="about-principle-no">03</span>
          <div>
            <h3>留一点手作痕迹</h3>
            <p>奶油纸面、胶带、手绘图标和轻微的不整齐，提醒你这背后是一个人在慢慢整理。</p>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="about-section" id="about-build">
    <header class="about-section-head">
      <span class="about-section-no">02</span>
      <div>
        <span class="about-section-kicker">HOW IT GREW</span>
        <h2>它是怎么长出来的</h2>
      </div>
      <p>先让骨架站稳，再让内容住进来，最后把纸面和细节一张张贴好。</p>
    </header>

    <ol class="about-timeline">
      <li>
        <span class="about-timeline-no">01</span>
        <div class="about-timeline-body">
          <h3>先有骨架</h3>
          <p>微内核只负责路由、插件、版本和 HTTP 契约；页面交给主题，功能交给插件。</p>
        </div>
        <span class="about-timeline-tag">微内核</span>
      </li>
      <li>
        <span class="about-timeline-no">02</span>
        <div class="about-timeline-body">
          <h3>再让内容住进来</h3>
          <p>文章、归档、标签、独立页面和后台编辑依次接上，先让文字有地方安放。</p>
        </div>
        <span class="about-timeline-tag">插件</span>
      </li>
      <li>
        <span class="about-timeline-no">03</span>
        <div class="about-timeline-body">
          <h3>把纸面贴好</h3>
          <p>奶油米色、纸纤维、胶带边缘和自绘图标，让每一次翻页都像在打开一本真的手账。</p>
        </div>
        <span class="about-timeline-tag">主题</span>
      </li>
      <li>
        <span class="about-timeline-no">04</span>
        <div class="about-timeline-body">
          <h3>现在继续修边角</h3>
          <p>顶栏即时搜索、版本升级和移动端布局，当前版本 {$appVersion} 仍在慢慢打磨。</p>
        </div>
        <span class="about-timeline-tag">进行中</span>
      </li>
    </ol>
  </section>

  <section class="about-boundary" id="about-boundary">
    <article class="about-boundary-card about-boundary-card--yes">
      <span class="about-boundary-kicker">03 / HERE</span>
      <h2>这里有什么</h2>
      <ul>
        <li><span class="about-bullet">{$checkIcon}</span><span>可以慢慢读的文章、归档和标签索引。</span></li>
        <li><span class="about-bullet">{$checkIcon}</span><span>一个不需要登录、不设阅读门槛的公开站点。</span></li>
        <li><span class="about-bullet">{$checkIcon}</span><span>RSS、Atom、JSON Feed 和公开接口，愿意被订阅和带走。</span></li>
        <li><span class="about-bullet">{$checkIcon}</span><span>清楚写下来的版本、备份与升级说明。</span></li>
      </ul>
    </article>

    <article class="about-boundary-card about-boundary-card--no">
      <span class="about-boundary-kicker">NOT HERE</span>
      <h2>这里没有什么</h2>
      <ul>
        <li><span class="about-bullet">{$closeIcon}</span><span>没有催更弹窗、自动播放和无限下滑的信息流。</span></li>
        <li><span class="about-bullet">{$closeIcon}</span><span>没有为了热闹而热闹的推荐位，也没有广告位。</span></li>
        <li><span class="about-bullet">{$closeIcon}</span><span>没有需要注册才能看全文的门槛。</span></li>
        <li><span class="about-bullet">{$closeIcon}</span><span>没有“必须每天更新”的承诺，只有尽量写好每一页。</span></li>
      </ul>
    </article>
  </section>

  <section class="about-section" id="about-reading">
    <header class="about-section-head">
      <span class="about-section-no">04</span>
      <div>
        <span class="about-section-kicker">A SMALL READING NOTE</span>
        <h2>给你的一条阅读建议</h2>
      </div>
      <p>不用一次读完，挑一页和今天有关的就好。</p>
    </header>

    <div class="about-promises">
      <article>
        <span class="about-promise-icon">{$plannerIcon}</span>
        <h3>从最近的开始</h3>
        <p>先看最新一篇，再顺着归档往回翻。每一页都保留了当时的上下文。</p>
      </article>
      <article>
        <span class="about-promise-icon">{$penIcon}</span>
        <h3>带着问题读</h3>
        <p>如果某一页正好回答了你的问题，可以把它存下来；不必记住所有细节。</p>
      </article>
      <article>
        <span class="about-promise-icon">{$leafIcon}</span>
        <h3>慢一点也没关系</h3>
        <p>这里没有阅读进度条。读到一半去泡杯茶，回来再翻也不会错过什么。</p>
      </article>
    </div>
  </section>

  <footer class="about-footer">
    <div class="about-signature">
      <span>写于 {$writtenAt}</span>
      <strong>慢慢来，比较快。</strong>
    </div>
    <div class="about-footer-links">
      <a href="/feed.xml">{$rssIcon} RSS</a>
      <a href="/posts.html">文章</a>
      <a href="/archive.html">归档</a>
      <a href="/api.html">接口</a>
      <a href="/healthz">状态</a>
    </div>
    <p class="about-colophon">PHP 8.5 · MySQL 9.7 · 微内核与插件 · 原生 CSS/JS · 自绘图标 · 版本 {$appVersion}</p>
  </footer>
</section>
HTML,
    ];
};
