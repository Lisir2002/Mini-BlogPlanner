<section class="admin-docs">
  <header class="admin-docs-head">
    <div class="admin-docs-copy">
      <span class="eyebrow"><?= cream_icon('plug', 'icon-sm') ?> PRIVATE SPEC · API v1</span>
      <p>这里只对已登录的管理员开放。公开页面不再展示入口，OpenAPI 源文件也不在静态目录中。</p>
    </div>
    <div class="admin-docs-actions">
      <a class="button button-ghost" href="/admin/api-docs/openapi.yaml" download>下载 OpenAPI 3.2.1</a>
      <a class="button button-primary" href="/admin/tokens.html"><?= cream_icon('plug', 'icon-xs') ?> 管理 API 令牌</a>
    </div>
  </header>

  <div class="admin-docs-frame">
    <div id="api-reference" data-url="/admin/api-docs/openapi.yaml"></div>
    <noscript>
      <p class="admin-docs-noscript">接口文档需要 JavaScript 渲染，也可直接下载上面的 OpenAPI 文件。</p>
    </noscript>
  </div>
</section>
