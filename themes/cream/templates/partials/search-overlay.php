<div class="search-overlay" id="search-overlay" hidden>
  <div class="search-overlay-scrim" data-search-close></div>
  <section class="search-overlay-panel" role="dialog" aria-modal="true" aria-labelledby="search-overlay-title">
    <div class="search-overlay-head">
      <div>
        <span class="search-overlay-kicker">SEARCH THE JOURNAL</span>
        <strong class="search-overlay-title" id="search-overlay-title">翻一翻这本手账</strong>
      </div>
      <button class="search-overlay-close" type="button" aria-label="关闭搜索" data-search-close>
        <?= cream_icon('close', 'icon-sm') ?>
      </button>
    </div>

    <form class="search-overlay-form" action="/" method="get" role="search" data-search-form>
      <span class="search-overlay-field-icon" aria-hidden="true"><?= cream_icon('search', 'icon-sm') ?></span>
      <label class="sr-only" for="search-overlay-input">搜索文章</label>
      <input id="search-overlay-input" type="text" role="searchbox" inputmode="search" name="search" maxlength="60" autocomplete="off" spellcheck="false" enterkeyhint="search" placeholder="输入标题、摘要或正文里的词" data-search-input>
      <button class="search-overlay-clear" type="button" aria-label="清空搜索词" hidden data-search-clear>
        <?= cream_icon('close', 'icon-xs') ?>
      </button>
      <button class="search-overlay-submit" type="submit">搜索</button>
    </form>

    <p class="search-overlay-status" aria-live="polite" data-search-status>
      <span class="search-overlay-pulse" aria-hidden="true"></span>
      <span data-search-status-text>输入两个字开始搜索</span>
    </p>

    <div class="search-overlay-content" data-search-content>
      <div class="search-overlay-start" data-search-start>
        <div class="search-overlay-note">
          <span class="search-overlay-note-mark" aria-hidden="true">01</span>
          <div>
            <strong>搜索标题、摘要和正文</strong>
            <p>中文输入两个字更准；英文和数字可以直接搜。</p>
          </div>
        </div>

        <div class="search-overlay-recent" hidden data-search-recent>
          <div class="search-overlay-section-head">
            <span>最近搜过</span>
            <button type="button" data-search-recent-clear>清除</button>
          </div>
          <div class="search-overlay-chips" data-search-recent-list></div>
        </div>

        <div class="search-overlay-suggest">
          <div class="search-overlay-section-head"><span>不妨试试</span></div>
          <div class="search-overlay-chips">
            <button type="button" data-search-suggest="手账">手账</button>
            <button type="button" data-search-suggest="慢一点">慢一点</button>
            <button type="button" data-search-suggest="建站">建站</button>
            <button type="button" data-search-suggest="归档">归档</button>
          </div>
        </div>
      </div>

      <div class="search-overlay-results" hidden data-search-results></div>

      <div class="search-overlay-empty" hidden data-search-empty>
        <span class="search-overlay-empty-icon" aria-hidden="true"><?= cream_icon('search') ?></span>
        <strong>没有翻到这一页</strong>
        <p>换个更短的关键词，或去 <a href="/tags.html">标签页</a> 按主题找。</p>
      </div>

      <div class="search-overlay-empty is-error" hidden data-search-error>
        <span class="search-overlay-empty-icon" aria-hidden="true"><?= cream_icon('search') ?></span>
        <strong>搜索暂时打了个盹</strong>
        <p>稍后再试一次，或直接去 <a href="/posts.html">文章列表</a> 翻翻。</p>
      </div>
    </div>

    <div class="search-overlay-foot">
      <span><kbd>↑</kbd><kbd>↓</kbd> 选择</span>
      <span><kbd>Enter</kbd> 打开</span>
      <span><kbd>Esc</kbd> 关闭</span>
      <span class="search-overlay-foot-brand">MINI-BLOGPLANNER</span>
    </div>
  </section>
</div>
