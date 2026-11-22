# 路由与伪静态

## 设计原则

1. 路由的规范路径不带 `.html`；展示层只负责输出 HTML。
2. 对外链接统一使用 `.html`，例如 `/about.html`、`/posts/hello.html`。
3. `.html` 只是请求别名，不是文件后缀；不会为每篇文章生成静态文件。
4. 真实存在的静态文件优先：图片、字体、CSS、JS 由 Web 服务器直接返回。
5. 接口文档不再是公开静态页面，`/admin/api-docs.html` 走后台鉴权路由，`/api.html` 与 `/docs/` 在网关层返回 404。

## 处理链路

```text
GET /posts/hello.html
        │
        ▼
Nginx: try_files $uri /index.php?$query_string
        │  磁盘上没有 posts/hello.html
        ▼
public/index.php → Request(path=/posts/hello.html)
        │
        ▼
Router::normalize()：去掉 .html、去掉尾部 /
        │
        ▼
匹配 Route('/posts/{slug}')，参数 slug=hello
```

`/index.html` 会归一化为 `/`。`/about/` 与 `/about` 等价；带 `.html` 的 POST 也走同一套匹配，但是否允许由路由方法决定。

## 规范

- 路由声明只写无后缀路径：`new Route(['GET'], '/posts/{slug}', ...)`。
- 参数只允许 `[A-Za-z_][A-Za-z0-9_]*`，匹配单段、不允许 `/`；参数值做 `rawurldecode`。
- 生成链接必须走统一的 URL 助手，后续由 `CanonicalUrl` 负责补 `.html`、去除 `utm_*`。
- 301 规则：`/posts/hello` → `/posts/hello.html`；`/index.html` 保留，不跳转。
- 404 不再回退到首页，避免软 404。
- API 路径不参与 `.html` 归一化语义，`/api/v1/...` 保持原样。
- 媒体地址 `/media/{id}/{name}` 由 Media 插件读取，不经过 `.html` 归一化。

## Nginx

完整基线在 `deploy/nginx.conf.example`。关键行：

```nginx
location / {
    try_files $uri /index.php?$query_string;
}
```

不要配置成 `try_files $uri $uri.html ...`，否则 `/about.html` 会去找 `about.html.html`。伪静态由 PHP 路由统一处理，静态文件仍然优先。

限流 zone 在 `deploy/nginx-rate-limit.conf.example`，Cloudflare 回源地址在 `deploy/nginx-cloudflare-realip.conf.example`；两者都装进 `/etc/nginx/conf.d/`。一般页面 30r/s、API 15r/s、登录 5r/s、写请求 8r/s、媒体 50r/s，单 IP 并发 60，超限统一 429。应用层还会在路由前按路径再算一轮固定窗口，因此 Nginx 挡突发，PHP 挡持续刷量。

## 本地开发

`php -S` 不会自动把不存在的 `.html` 转给 `index.php`，使用：

```bash
php -S 127.0.0.1:8099 -t public public/router.php
```

`public/router.php` 只用于开发；生产使用 Nginx 配置。
