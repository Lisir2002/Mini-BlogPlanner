<?php
$app = (string) ($app ?? '0.1.0');
$schema = (string) ($schema ?? '');
$title = (string) ($site['title'] ?? '日记Planner');
?>
<section class="hero">
  <div class="hero-copy">
    <span class="eyebrow"><?= cream_icon('spark', 'icon-sm') ?> 今日开张</span>
    <h1>把代码和日子，<br><span class="marker">贴进同一本手账。</span></h1>
    <p class="hero-lead">一个不着急的写作角落。微内核、插件、主题和版本契约都已经就位，接下来让文字慢慢长出来。</p>
    <div class="hero-actions">
      <a class="button button-primary" href="/api.html"><?= cream_icon('book') ?> 看接口文档</a>
      <a class="button button-ghost" href="/healthz"><?= cream_icon('leaf') ?> 站点状态</a>
    </div>
    <div class="status-strip" aria-label="当前版本">
      <span class="status-pill"><b>app</b> v<?= cream_e($app) ?></span>
      <span class="status-pill"><b>schema</b> <?= cream_e($schema) ?></span>
      <span class="status-pill status-pill-accent">production</span>
    </div>
  </div>

  <aside class="hero-note" aria-label="手账便签">
    <div class="tape tape-coral" aria-hidden="true"></div>
    <p class="note-kicker">今日便签</p>
    <p>先有安静的骨架，<br>再有热闹的内容。</p>
    <div class="note-rule" aria-hidden="true"></div>
    <p class="note-small">— 写于 <?= cream_e(date('Y.m.d')) ?></p>
    <span class="note-sticker"><?= cream_icon('pen') ?></span>
  </aside>
</section>

<section class="section">
  <div class="section-head">
    <h2>今天贴上的三页</h2>
    <p>骨架已经能跑，内容随后。</p>
  </div>
  <div class="card-grid">
    <article class="note-card">
      <div class="tape tape-butter" aria-hidden="true"></div>
      <div class="note-icon note-icon-butter"><?= cream_icon('layers') ?></div>
      <div class="note-tag">微内核</div>
      <h3>内核只做内核的事</h3>
      <p>路由、插件、版本和 HTTP 各归其位。PHP 核心不碰 HTML，页面由主题接管。</p>
      <a class="text-link" href="/api.html">看它暴露了什么 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </article>
    <article class="note-card">
      <div class="tape tape-mint" aria-hidden="true"></div>
      <div class="note-icon note-icon-mint"><?= cream_icon('clock') ?></div>
      <div class="note-tag">版本契约</div>
      <h3>升级和回退都有据可查</h3>
      <p>结构版本、迁移账本、发布清单和三种回退模式，都能在命令行里被验证。</p>
      <a class="text-link" href="/api.html">看健康检查 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </article>
    <article class="note-card">
      <div class="tape tape-sky" aria-hidden="true"></div>
      <div class="note-icon note-icon-sky"><?= cream_icon('plug') ?></div>
      <div class="note-tag">插件与主题</div>
      <h3>万物皆插件</h3>
      <p>每个功能自带清单和路由边界；主题只负责好看，不替内核做决定。</p>
      <a class="text-link" href="/api.html">读接口规范 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </article>
  </div>
</section>

<?php if (($posts ?? []) !== []): ?>
<section class="section">
  <div class="section-head">
    <h2>刚刚写下的</h2>
    <p><a class="text-link" href="/posts.html">全部文章 <?= cream_icon('arrow-right', 'icon-xs') ?></a></p>
  </div>
  <div class="home-post-list">
  <?php foreach ($posts as $post): ?>
    <a class="home-post-row" href="/posts/<?= cream_e($post->slug) ?>.html">
      <span class="home-post-date"><?= cream_e(cream_date($post->publishedAt)) ?></span>
      <span class="home-post-title"><?= cream_e($post->title) ?></span>
      <span class="home-post-arrow"><?= cream_icon('arrow-right', 'icon-xs') ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="section section-journal">
  <div class="journal-paper">
    <div class="journal-heading">
      <span class="journal-stamp"><?= cream_icon('calendar') ?></span>
      <h2>这本手账接下来写什么</h2>
    </div>
    <ul class="journal-list">
      <li><span class="check-dot"></span><span>文章、标签与系列，先让内容有地方住。</span></li>
      <li><span class="check-dot"></span><span>独立页面接入站长自己的 PHP 与样式。</span></li>
      <li><span class="check-dot"></span><span>后台编辑、媒体库与每日备份。</span></li>
      <li><span class="check-dot"></span><span>把 40 枚自绘图标、字体和动效接进主题。</span></li>
    </ul>
    <div class="journal-signature">慢慢来，比较快。</div>
  </div>
</section>
