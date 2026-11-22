# API 规范

## 基本约定

- 版本：`version.json` 的 `api` 轴；当前 `1.5.2`。URL 前缀 `/api/v1`。
- 传输：仅 HTTPS；请求与响应均为 JSON，字符集 UTF-8。
- 兼容：新增可选字段不升主版本；破坏性变更升 `minor` 并保留旧路径一个发布周期。
- 错误：统一 `application/problem+json`，字段 `type`、`title`、`status`、`detail`、`instance`，遵循 RFC 9457。
- 时间：ISO 8601 UTC；数据库存储 UTC。
- 缓存：读取接口默认 `no-store`；公开只读内容可按资源单独协商。
- 认证：公开文章、搜索、标签与站点状态无需认证；`/api/v1/admin/*` 必须携带 `Authorization: Bearer mbp_<token>`，并按令牌作用域授权。
- 令牌：后台“API”菜单创建，明文只显示一次，数据库仅保存 SHA-256 哈希；可设置有效期并随时撤销。
- 限流：公开接口按 IP 120 次/分钟，管理接口再按令牌 120 次/分钟；超限返回 `429`，响应头带 `Retry-After` 和 `X-RateLimit-*`。
- 页面列表：固定每页 10 条，超出自动分页；API 读取接口使用 `?page=1&per_page=20`，响应包含 `data`、`meta`，`per_page` 上限 100。

## 已实现接口

### `GET /api/v1/health`

检查数据库连接与结构版本，不泄露连接信息。

**200**

```json
{
  "status": "ok",
  "app": "0.1.71",
  "api": "1.5.2",
  "schema": 2026112106
}
```

**503**

- `type=/problems/schema-missing`：版本账本不存在，未安装或初始化失败。
- `type=/problems/schema-ahead`：数据库结构比代码新，需要回退发布或升级代码。
- `type=/problems/schema-behind`：数据库结构落后，需要执行 CLI 升级。
- `type=/problems/service-unavailable`：数据库不可达或查询失败。

### `GET /healthz`

与 `/api/v1/health` 同义，给容器探针和部署脚本使用。未安装时由 `InstallGate` 直接返回：

```json
{"status":"not-installed"}
```

状态码 `503`。

### `GET /api/v1/status`

返回完整站点状态：应用与 API 版本、数据库连接、结构版本、PHP 运行环境、公开内容计数和检查结果。只包含公开可读信息，不返回连接地址、账号或服务器路径。正常返回 `200`，数据库或结构异常时返回同样的状态对象与 `503`。

```json
{
  "status": "ok",
  "app": "0.1.71",
  "api": "1.5.2",
  "channel": "stable",
  "checked_at": "2026-11-22T08:00:00+00:00",
  "latency_ms": 3,
  "schema": 2026112106,
  "database": {"connected": true, "driver": "mysql", "latency_ms": 3},
  "content": {"published_posts": 19, "approved_comments": 5, "media": 3, "tags": 4},
  "checks": [
    {"id": "database", "label": "数据库连接", "status": "ok", "detail": "连接正常，可以读取站点数据。", "metric": "3 ms"}
  ]
}
```


### 受保护接口与 API 令牌

管理接口统一挂在 `/api/v1/admin/` 下，必须携带 Bearer 令牌：

```http
GET /api/v1/admin/status HTTP/1.1
Authorization: Bearer mbp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

令牌在后台“API”菜单创建，作用域包括：

| 作用域 | 允许访问 |
| --- | --- |
| `status:read` | `/api/v1/admin/status` 运行状态 |
| `content:read` | `/api/v1/admin/posts` 后台文章列表 |
| `comments:read` | `/api/v1/admin/comments` 后台留言列表 |

- 缺少、无效、过期或已撤销：`401`，响应头带 `WWW-Authenticate: Bearer`。
- 令牌有效但作用域不足：`403`，`type=/problems/api-scope-forbidden`。
- 令牌明文只在创建成功时显示一次；后台和审计只保留前缀，不保留完整值。
- 管理接口响应均为 `Cache-Control: no-store`；留言接口不返回邮箱。

### `GET /api/v1/posts`

列出已发布文章，按发布时间倒序，最多 50 条。

```json
{
  "data": [
    {
      "slug": "hello",
      "title": "第一篇",
      "excerpt": "摘要",
      "published_at": "2026-11-20T08:00:00+08:00",
      "reading_minutes": 3,
      "url": "/posts/hello.html",
      "tags": ["手账"]
    }
  ],
  "meta": {"count": 1}
}
```

草稿、归档和 `published_at` 晚于当前时间的文章不会返回。

### `GET /api/v1/search`

按关键词检索已发布文章。`q` 为空返回 `422`；单个汉字会退回包含匹配；其余查询使用 ngram 全文索引。

**200**

```json
{
  "data": [
    {
      "slug": "hello",
      "title": "第一篇",
      "excerpt": "摘要",
      "published_at": "2026-11-20T08:00:00+08:00",
      "reading_minutes": 3,
      "url": "/posts/hello.html",
      "tags": ["手账"]
    }
  ],
  "meta": {"query": "手账", "count": 1}
}
```

**422**

- `type=/problems/search-query-required`：`q` 为空或只有空白字符。

**503**

- `type=/problems/service-unavailable`：数据库不可用。

响应带 `Cache-Control: no-store`。

### `GET /api/v1/posts/{slug}`

返回单篇文章，额外包含 `body_html` 和 `reactions` 汇总。`slug` 只允许小写字母、数字、短横线。

- `404 /problems/post-not-found`：不存在或未发布。
- `503 /problems/service-unavailable`：数据库不可用。

`reactions` 包含公开的 `likes` 与 `dislikes` 计数，不包含访客身份。

正文 `body_html` 已经过后台 HTML 白名单过滤；第三方调用方如果直接渲染，仍应结合自己的上下文做转义或二次白名单。

### `GET /api/v1/tags`

列出有已发布文章的标签，按文章数量倒序。

```json
{
  "data": [
    {"name": "手账", "slug": "shou-zhang", "url": "/tags/shou-zhang.html", "post_count": 2}
  ],
  "meta": {"count": 1}
}
```

### `GET /api/v1/tags/{slug}`

返回标签信息和该标签下的已发布文章。`slug` 只允许小写字母、数字、短横线。

- `404 /problems/tag-not-found`：标签不存在或没有已发布文章。
- `503 /problems/service-unavailable`：数据库不可用。

## 订阅端点

订阅不走 `/api/v1`，直接输出 Feed 文件：

- `GET /feed.xml`：RSS 2.0。
- `GET /atom.xml`：Atom 1.0。
- `GET /feed.json`：JSON Feed 1.1。

三种格式字段、缓存与校验方式见 [订阅 Feed](api/feeds.md)。

## OpenAPI

- 源文件：`public/docs/openapi.yaml`（OpenAPI 3.2.1）。
- 渲染页：`/api.html`（Scalar，读取 YAML）。
- 变更接口时必须同步修改：控制器、OpenAPI、`docs/API.md`、契约测试。

## 新增接口检查表

1. 先定义路径、方法、请求体、成功响应、错误 `type`。
2. 在 OpenAPI 中写 `operationId`、`summary`、`tags`、示例。
3. 控制器只做参数校验和调用服务，不直接拼 HTML。
4. 错误必须走 `Response::problem()`，不返回裸 500。
5. 写接口必须校验 CSRF，并记录审计日志。
6. 至少一条契约测试覆盖成功路径和一条错误路径。
