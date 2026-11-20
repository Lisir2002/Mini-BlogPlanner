# 订阅 Feed

站点提供三种订阅格式，内容一致，按发布时间倒序输出最近 20 篇已发布文章：

| 地址 | 格式 | Content-Type |
|---|---|---|
| `/feed.xml` | RSS 2.0 | `application/rss+xml; charset=utf-8` |
| `/atom.xml` | Atom 1.0 | `application/atom+xml; charset=utf-8` |
| `/feed.json` | JSON Feed 1.1 | `application/feed+json; charset=utf-8` |

`/feed` 是 `/feed.xml` 的便捷别名，返回同一份 RSS。

## 内容范围

- 只包含 `status = published` 且发布时间已到的文章。
- 草稿、未来文章、搜索页和后台不会进入 Feed。
- 每条包含标题、绝对链接、摘要、正文 HTML、发布时间、修改时间和标签。
- RSS 使用 `content:encoded` 输出全文；Atom 使用 `<content type="html">`；JSON Feed 使用 `content_html`。
- 文章链接由 slug 生成，站内地址统一为 `/posts/{slug}.html`。

## 地址来源

优先使用 `.env` 中的 `APP_URL`，保证订阅阅读器拿到稳定绝对地址；未配置时按当前请求的 Host 生成。绑定正式域名后只需修改 `APP_URL`，三种 Feed、canonical 和 sitemap 会一起切换。

页面 `<head>` 同时输出三种发现链接：

```html
<link rel="alternate" type="application/rss+xml" href=".../feed.xml">
<link rel="alternate" type="application/atom+xml" href=".../atom.xml">
<link rel="alternate" type="application/feed+json" href=".../feed.json">
```

## 日期与时区

发布时间和修改时间读取 `APP_TIMEZONE`，默认 `UTC`。RSS 使用 RFC 2822，Atom 和 JSON Feed 使用 RFC 3339。数据库中的时间按站点时区解释，不额外改写历史时间。

## 缓存与失败

- 成功响应缓存 5 分钟，`Cache-Control: public, max-age=300`。
- 数据库不可用时返回 503 和对应格式的错误页，缓存禁用。
- Feed 是只读端点，不记录访问者，不写 Cookie 以外的会话状态。

## 校验示例

```bash
curl -fsS https://example.com/feed.xml | xmllint --noout -
curl -fsS https://example.com/atom.xml | xmllint --noout -
curl -fsS https://example.com/feed.json | jq '.version, (.items | length)'
```

`xmllint` 只检查 XML 结构；发布前还应在真实阅读器中抽查标题、正文和标签是否正确。
