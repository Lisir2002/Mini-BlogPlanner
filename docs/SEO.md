# SEO 与站点地图

## 站点地图

```text
GET /sitemap.xml
```

只列公开内容：

- 首页 `/`
- 文章列表 `/posts.html`
- 标签列表 `/tags.html`
- 独立页面 `/{slug}.html`，由 `content/pages/` 下的文件自动发现
- 已发布文章 `/posts/{slug}.html`，带 `lastmod`
- 有已发布文章的标签 `/tags/{slug}.html`

草稿、归档、未来文章和搜索结果不会进入站点地图。地址优先取 `APP_URL`，未配置时按当前请求的 Host 生成。响应为 `application/xml`，缓存 1 小时。

## 爬虫规则

```text
GET /robots.txt
```

- 允许抓取公开页面。
- 禁止抓取 `/admin`、`/search` 和 `/api/v1`。
- 末尾给出当前站点的 `Sitemap` 地址。

`/search` 仍然返回 `X-Robots-Tag: noindex, follow`，robots 只是减少无效抓取。

## Canonical

以下页面会输出绝对地址的 canonical：

- 首页
- 文章列表
- 标签列表与标签页
- 独立页面
- 文章页

`/search` 不输出 canonical，避免把查询页当成独立内容。

## 结构化数据

主题在 `<head>` 输出 `application/ld+json`：

- 首页：`WebSite`，带 `SearchAction`，指向 `/search?q={search_term_string}`。
- 文章：`BlogPosting`，包含标题、摘要、发布时间、修改时间、作者/发布者、主页面和标签关键词。
- 文章列表与标签：`CollectionPage`。
- 独立页面：`WebPage`。

作者与发布者使用站点名，避免凭空编造个人身份。JSON 编码启用 `JSON_HEX_TAG`，标题或摘要中的 `<` 不会提前闭合脚本标签。

## 缓存与边界

- `sitemap.xml` 与 `robots.txt` 均为只读，不记录访问者。
- 新文章发布后最多 1 小时进入缓存；需要立即刷新时可重启 PHP-FPM 或等缓存过期。
- 页面 slug 只接受小写字母、数字和短横线，和独立页面运行时一致。
