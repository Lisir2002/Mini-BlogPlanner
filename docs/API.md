# API 规范

## 基本约定

- 版本：`version.json` 的 `api` 轴；当前 `1.7.0`。URL 前缀 `/api/v1`。
- 传输：仅 HTTPS；请求与响应均为 JSON，字符集 UTF-8。
- 兼容：新增可选字段不升主版本；破坏性变更升 `minor` 并保留旧路径一个发布周期。
- 错误：统一 `application/problem+json`，字段 `type`、`title`、`status`、`detail`、`instance`，遵循 RFC 9457。
- 时间：ISO 8601 UTC；数据库存储 UTC。
- 缓存：读取接口默认 `no-store`；公开只读内容可按资源单独协商。
- 认证：公开文章、搜索、标签与站点状态无需认证；`/api/v1/admin/*` 必须携带 `Authorization: Bearer mbp_<token>`，并按令牌作用域授权。
- 令牌：后台“API”菜单创建，明文只显示一次，数据库仅保存 SHA-256 哈希；可设置有效期并随时撤销。
- 限流：公开接口 300 次/分钟/IP，管理接口 240 次/分钟/令牌并叠加 600 次/分钟/IP；超限返回 `429`，响应头带 `Retry-After` 和 `X-RateLimit-*`。
- 页面列表：固定每页 10 条，超出自动分页；API 读取接口使用 `?page=1&per_page=20`，响应包含 `data`、`meta`，`per_page` 上限 100。

## 已实现接口

### `GET /api/v1/health`

检查数据库连接与结构版本，不泄露连接信息。

**200**

```json
{
  "status": "ok",
  "app": "0.1.72",
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
  "app": "0.1.72",
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
| `comments:read` | `GET /api/v1/admin/comments` 后台留言列表、筛选与计数 |
| `comments:write` | `PATCH/DELETE /api/v1/admin/comments/{id}` 与批量接口，审核、撤回、标为垃圾和删除留言 |
| `tags:read` | `/api/v1/admin/tags` 查看标签统计、列表与单个标签 |
| `tags:write` | `/api/v1/admin/tags` 创建、重命名、合并和删除标签 |

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

### 后台标签

后台标签接口需要 `tags:read` 或 `tags:write` 作用域，响应统一 `Cache-Control: no-store`。

| 方法 | 路径 | 作用域 | 说明 |
| --- | --- | --- | --- |
| `GET` | `/api/v1/admin/tags` | `tags:read` | 列表，支持 `q`、`status`、`sort`、`page`、`per_page` |
| `GET` | `/api/v1/admin/tags/{id}` | `tags:read` | 单个标签详情 |
| `POST` | `/api/v1/admin/tags` | `tags:write` | 新建标签，成功 `201` 并返回 `Location` |
| `PATCH` | `/api/v1/admin/tags/{id}` | `tags:write` | 重命名，只改名称，不改 slug |
| `POST` | `/api/v1/admin/tags/{id}/merge` | `tags:write` | 合并到 `target_id`，来源标签随后删除 |
| `DELETE` | `/api/v1/admin/tags/{id}` | `tags:write` | 删除空标签，成功 `204` |

列表响应：

```json
{
  "data": [
    {
      "id": 3,
      "name": "手账",
      "slug": "shou-zhang",
      "url": "/tags/shou-zhang.html",
      "post_count": 6,
      "published_count": 4,
      "status": "used",
      "created_at": "2026-10-01T08:00:00+00:00",
      "last_used_at": "2026-11-22T06:30:00+00:00"
    }
  ],
  "meta": {
    "total": 12,
    "page": 1,
    "pages": 2,
    "per_page": 10,
    "status": "all",
    "sort": "usage",
    "q": "",
    "summary": {"total": 12, "used": 9, "empty": 3, "links": 24, "top_name": "手账", "top_count": 6}
  }
}
```

- `status=used` 只返回有文章关联的标签，`status=empty` 只返回空标签；`sort=usage|name|recent`。
- 名称去 HTML 标签、合并空白后最多 60 字；名称冲突返回 `409 /problems/tag-name-conflict`。
- 有文章的标签不能直接删除，返回 `409 /problems/tag-not-empty`，应先合并。
- 合并目标必须存在且不能是来源自身，否则 `422 /problems/tag-merge-invalid`。
- 标签不存在返回 `404 /problems/tag-not-found`；请求体不是 JSON 对象返回 `400 /problems/tag-body-invalid`。
- API 写操作会写入审计，操作者记录为 `api:<令牌名称>`。

### 后台留言

`GET /api/v1/admin/comments` 需要 `comments:read`，支持 `status`（`pending`、`approved`、`spam`）、`q`、`post_id`、`page` 和 `per_page`。`q` 匹配称呼、正文、邮箱或文章标题；`meta.counts` 返回 `all`、`pending`、`approved`、`spam` 四个计数。响应包含父留言的作者与 90 字摘要，不返回留言邮箱。

```http
GET /api/v1/admin/comments?status=pending&post_id=12&q=%E6%84%8F%E6%83%8F&page=1 HTTP/1.1
Authorization: Bearer mbp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

单条改状态使用 `PATCH`，请求体只接受 `status`：

```http
PATCH /api/v1/admin/comments/31 HTTP/1.1
Authorization: Bearer mbp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Content-Type: application/json

{"status":"approved"}
```

`approved` 会写入通过时间，`pending` 撤回到待审队列，`spam` 标为垃圾。状态非法返回 `422`，留言不存在返回 `404`。

删除使用 `DELETE /api/v1/admin/comments/{id}`，成功返回 `204`；删除父留言会级联删除其回复。

批量接口为 `POST /api/v1/admin/comments/bulk`：

```json
{"action": "approved", "ids": [31, 32, 33]}
```

`action` 支持 `approved`、`pending`、`spam`、`delete`，编号最多 100 个。响应返回 `action`、`affected` 和去重后的 `ids`。所有写操作写入审计，事件形如 `comment.approved`、`comment.bulk.deleted`，payload 带令牌名称与编号，不记录正文和邮箱。

## 订阅端点

订阅不走 `/api/v1`，直接输出 Feed 文件：

- `GET /feed.xml`：RSS 2.0。
- `GET /atom.xml`：Atom 1.0。
- `GET /feed.json`：JSON Feed 1.1。

三种格式字段、缓存与校验方式见 [订阅 Feed](api/feeds.md)。

## OpenAPI

- 源文件：`docs/openapi.yaml`（OpenAPI 3.2.1，位于 Web 根目录之外）。
- 渲染页：`/admin/api-docs.html`（Scalar，读取 `/admin/api-docs/openapi.yaml`）。
- 访问权限：页面与 YAML 都要求有效管理员会话；公开站点的首页、页头、页脚、状态页不再提供入口。
- 公开静态路径 `/api.html` 与 `/docs/` 在 Nginx 层直接返回 404，避免旧缓存或直接地址绕过登录。
- 变更接口时必须同步修改：控制器、OpenAPI、`docs/API.md`、契约测试。

## 新增接口检查表

1. 先定义路径、方法、请求体、成功响应、错误 `type`。
2. 在 OpenAPI 中写 `operationId`、`summary`、`tags`、示例。
3. 控制器只做参数校验和调用服务，不直接拼 HTML。
4. 错误必须走 `Response::problem()`，不返回裸 500。
5. 写接口必须校验 CSRF，并记录审计日志。
6. 至少一条契约测试覆盖成功路径和一条错误路径。
