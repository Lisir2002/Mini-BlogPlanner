# 后台管理

## 入口与认证

- 登录入口：后台菜单中的“登录”，匿名访问 `GET /admin` 会跳转到 `GET /admin/login.html`；公开站点不再放置登录链接。
- 接口文档：`GET /admin/api-docs.html`，仅登录后可见；OpenAPI 源文件由 `GET /admin/api-docs/openapi.yaml` 在同一会话内输出。
- 图形验证码：`GET /admin/captcha`
- 登录提交：`POST /admin/login.html`，必须带 `captcha` 字段
- 退出：`POST /admin/logout.html`
- 后台首页：`GET /admin.html` → 302 到文章列表
- 管理员来自 `admins` 表；安装器创建第一个管理员。
- 密码使用 Argon2id；登录用 `password_verify`。
- 登录成功 `session_regenerate_id(true)`，并轮换 CSRF token。
- 会话闲置 2 小时自动失效；Cookie 为 HttpOnly、SameSite=Lax，HTTPS 下 Secure。
- 登录页使用站内生成的图形验证码，不请求任何外部验证服务。
- 验证码答案只保存在会话中，5 分钟有效，最多尝试 5 次；成功或失败都会消耗当前题目，刷新后换新题。
- 验证码图片禁止缓存、禁止跨域复用和搜索引擎索引；验证码是额外门槛，不能替代密码校验与 IP 节流。
- 登录失败按 IP 节流：15 分钟内 20 次；入口另有 60 次 POST / 5 分钟与 Nginx 5r/s 限速，超限返回 429。
- 所有写操作必须带 `_csrf`；失败返回 419。
- 版本与一键升级：见 `docs/ADMIN-UPDATES.md`，页面入口为 `GET /admin/updates.html`。
- API 令牌：见“API 令牌管理”一节，页面入口为 `GET /admin/tokens.html`；接口文档入口为 `GET /admin/api-docs.html`。

## 文章管理

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| GET | `/admin/posts.html` | 文章列表，含草稿/发布/归档 |
| GET | `/admin/posts/new.html` | 新建表单 |
| POST | `/admin/posts.html` | 创建文章 |
| GET | `/admin/posts/{id}.html` | 编辑表单 |
| GET | `/admin/posts/{id}/preview.html` | 预览文章（含草稿） |
| POST | `/admin/posts/{id}.html` | 更新文章 |
| POST | `/admin/posts/{id}/archive.html` | 归档文章 |
| POST | `/admin/posts/{id}/restore.html` | 把归档文章恢复为草稿 |
| POST | `/admin/posts/bulk.html` | 批量归档或恢复，最多 100 篇 |
| GET | `/admin/media.html` | 媒体库与上传表单 |
| POST | `/admin/media.html` | 上传图片（字段 `file`） |
| POST | `/admin/media/{id}/delete.html` | 删除图片与文件 |
| GET | `/admin/audit.html` | 审计事件列表，支持分类、关键词、操作者、结果、日期筛选与分页 |
| GET | `/admin/audit/export.csv` | 按当前筛选导出审计 CSV，单次最多 5000 条 |
| GET | `/admin/tokens.html` | API 令牌列表，每页 10 条 |
| POST | `/admin/tokens.html` | 创建令牌，字段 `name`、`scopes[]`、`expires`、`_csrf` |
| POST | `/admin/tokens/{id}/revoke.html` | 撤销令牌 |

表单字段：

- `title`：1–200 字符。
- `slug`：小写字母、数字、单个短横线，1–190 字符；公开地址 `/posts/{slug}.html`。
- `excerpt`：最多 500 字符。
- `body`：HTML，1–200000 字符；保存前经过白名单过滤。
- `tags`：逗号分隔，最多 8 个，每个最多 60 字符；同名标签自动复用。
- `status`：`draft` 或 `published`。
- `_csrf`：会话 token。

行为：

- 发布时写入 `published_at`；改回草稿清空发布时间。
- 阅读时长按去标签正文每 500 字约 1 分钟计算，范围 1–120。
- 作者取当前管理员 id；`author_id` 外键在管理员删除时置空。
- slug 唯一冲突时返回表单错误，不会写入半条数据。
- 归档后公开列表和 API 不再返回；恢复只回到 `draft`，不会自动发布。
- 批量操作只接受 `archive`/`restore`，单次最多 100 篇；成功数按实际变更计算。
- 标签同步与文章写入在同一个事务内完成，失败不会留下半条关联。
- 正文白名单保留段落、标题、列表、引用、代码块、链接和图片；移除脚本、样式、表单、内嵌页面、事件属性和危险协议。
- 中文标签会转写为拼音 slug；无法转写时回退到稳定哈希。

文章列表查询：

- `status`：`published`、`draft`、`archived`；省略表示全部。
- `q`：匹配标题或 slug，最多 80 字符。
- `tag`：标签 slug，必须存在。
- `sort`：`updated`（默认）、`published`、`title`。
- `changes=1`：只看已发布且存在 `post_drafts` 的文章。
- `page`：每页 10 篇；筛选后为空时显示专用空状态。
- 列表在桌面显示七列表格，760px 以下及平板宽度切换为卡片；行菜单提供预览草稿、公开页、版本历史、归档/恢复。

## 留言管理

后台导航“评论”进入留言收件箱，按待审核、已通过、垃圾和全部四种状态组织。

- 页首显示待审计数和总留言数，状态标签保留当前关键词与文章筛选。
- 筛选支持关键词、文章下拉和状态组合；关键词匹配称呼、正文、邮箱和文章标题，清空后回到全部留言。
- 列表按时间倒序，每页 10 条；卡片显示作者、邮箱、状态、时间、文章来源，回复会同时显示父留言作者和摘要。
- 单条操作可通过、撤回待审、标为垃圾或删除；删除父留言会级联删除其回复。
- 批量栏依赖 `admin-comments.js`：全选本页后可通过、撤回待审、标为垃圾或删除，一次最多 100 条，删除前二次确认。
- 页面加载 `admin-comments.css`；移动端隐藏宣传抬头，四个状态标签和筛选栏各压成一行，批量操作改为四列一行，留言卡片保持首屏可见。
- 以上操作均校验 CSRF 并写入审计，事件为 `comment.approved`、`comment.pending`、`comment.spam`、`comment.deleted`、`comment.bulk.*`。

## API 令牌管理

后台导航“API”进入令牌页，页面分为令牌列表和创建表单。

- 令牌格式为 `mbp_` 加 64 位随机十六进制；数据库只保存 SHA-256 哈希，原始值只在创建成功后的当前页面显示一次。
- 作用域：`status:read` 访问 `/api/v1/admin/status`，`content:read` 访问 `/api/v1/admin/posts`，`comments:read` 读取 `/api/v1/admin/comments`，`comments:write` 审核、撤回、标为垃圾和删除留言。
- 有效期可选 7、30、90、365 天或长期有效；撤销后立即失效，列表保留记录、前缀、最近使用时间与撤销状态。
- 创建和撤销写入审计事件 `api.token.created`、`api.token.revoked`；payload 只记录 id、前缀、名称、作用域和到期时间，不记录完整令牌。
- 管理接口不接受后台会话代替令牌，必须使用 `Authorization: Bearer <token>`。

## 异步表单约定

- 后台所有普通表单由 `assets/cream.js` 接管，默认把响应中的 `#admin-main` 替换回当前页面；服务端不需要额外 JSON 接口。
- 退出表单标记 `data-ajax-full`，整页替换后由脚本提示已退出；登录表单使用原生提交，完成 303 跳转后再进入后台，避免异步登录丢失会话。
- 需要换目标容器时用 `data-ajax-target` 指定选择器；指定 `body` 等同整页替换。
- 上传表单自动走 `XMLHttpRequest`，显示百分比；普通表单走 `fetch`，保留 303 跳转和服务端校验错误。
- 会话失效跳回登录页时，脚本会提示重新登录，不再误报“已完成”。
- 所有请求带 `X-Requested-With: XMLHttpRequest`，但仍以会话 Cookie 和 `_csrf` 为准。
- 禁用 JavaScript 时遮罩自动隐藏，单篇表单按原生 `action`/`method` 提交；批量栏和行菜单依赖脚本，未启用时仍可逐篇编辑、归档或恢复。

## 安全边界

- 后台页面和表单都在 `plugins/admin` 与主题模板里，内核不拼 HTML。
- 当前不提供网页端改密码、改邮箱、上传插件或编辑 PHP 页面；这些属于后续 CLI/受控功能。
- 页面正文保存前经过 HTML 白名单，公开页只渲染过滤后的结果。
- `content/pages/` 的 PHP 仍是站长文件系统权限，不等价于后台权限。

## 尚未实现

- 定时发布、密码重置 CLI、二步验证。
- 多管理员权限细分（当前 role 字段已预留）。


## 预览与审计

- 预览必须登录，公开页使用的模板会显示“预览模式”，响应带 `X-Robots-Tag: noindex, nofollow`。
- 审计页只读；事件包括 `admin.login.succeeded/failed`、`admin.logout`、`post.created/updated/archived/restored`、`post.bulk_archived/bulk_restored`、`media.uploaded/deleted`、`tag.renamed/merged/deleted`、`system.audit.pruned`、`api.token.created/revoked`。
- 查询参数：`category`（`admin/post/comment/media/tag/system/other`）、`q`（事件、操作者或字段）、`event` 精确事件名、`actor` 精确用户名、`result`（`success/failed`）、`range`（`today/24h/7d/30d/90d`）、`from`/`to`（`YYYY-MM-DD`）、每页固定 10 条（不再提供切换）、`page` 页码；超出页数会夹到最后一页。
- 审计详情展开后显示结构化字段和脱敏后的原始 JSON；密码、令牌、Cookie、授权头、CSRF 和会话字段在写入和读取时都会遮蔽。
- CSV 导出复用当前筛选，单次上限 5000 条，导出动作本身写入 `admin.audit.exported`；后台不提供编辑或删除入口。
- 审计只记录动作、对象 id、状态、IP，不记录密码、Cookie、数据库连接串。
- 保留期清理：`php bin/audit-prune.php --days=90 --json` 默认试运行；加 `--confirm` 删除早于窗口的记录，并写一条 `system.audit.pruned`。最小窗口 7 天，最大 3650 天。


## 标签管理

- `GET /admin/tags.html`：列出标签、slug 和文章数。
- `POST /admin/tags/{id}/rename.html`：字段 `name`，只改显示名，slug 不变，公开链接不失效。
- `POST /admin/tags/{id}/merge.html`：字段 `target_id`，把源标签的文章关联迁移到目标标签，再删除源标签。
- `POST /admin/tags/{id}/delete.html`：仅允许删除文章数为 0 的空标签。
- 所有写操作要求 CSRF；成功返回 303 到 `/admin/tags.html?ok=...`，参数错误 422，数据库错误 503。
- 审计事件：`tag.renamed`、`tag.merged`、`tag.deleted`。
