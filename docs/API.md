# API 规范

## 基本约定

- 版本：`version.json` 的 `api` 轴；当前 `1.3.0`。URL 前缀 `/api/v1`。
- 传输：仅 HTTPS；请求与响应均为 JSON，字符集 UTF-8。
- 兼容：新增可选字段不升主版本；破坏性变更升 `minor` 并保留旧路径一个发布周期。
- 错误：统一 `application/problem+json`，字段 `type`、`title`、`status`、`detail`、`instance`，遵循 RFC 9457。
- 时间：ISO 8601 UTC；数据库存储 UTC。
- 缓存：读取接口默认 `no-store`；公开只读内容可按资源单独协商。
- 认证：公开读取接口无需认证；写接口使用后台会话 + CSRF，后续补充 Token 插件。
- 限流：默认按 IP 计数；超限返回 `429`，响应头带 `Retry-After`。
- 分页：`?page=1&per_page=20`，响应包含 `data`、`meta`；`per_page` 上限 100。

## 已实现接口

### `GET /api/v1/health`

检查数据库连接与结构版本，不泄露连接信息。

**200**

```json
{
  "status": "ok",
  "app": "0.1.33",
  "api": "1.3.0",
  "schema": 2026112007
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
