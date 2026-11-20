# 后台文章编辑器设计规范 v2

> 状态：待评审
>
> 目标版本：v0.1.41 起分阶段落地
>
> 范围：后台文章编辑页、内容格式、保存与发布流程、编辑器交互、视觉系统
>
> 适用：Mini-BlogPlanner 后台；公开文章渲染沿用现有主题
>
> 视觉稿：`previews/editor-redesign-v2.html`，对应 Phase 2 目标形态；Phase 1 先落地双源、预览与新布局

## 0. 文档约定

- **规范编号**：`ED-xxx` 为编辑器需求，`ADR-xxx` 为架构决策，`AC-xxx` 为验收条件。
- **工作稿**：后台正在编辑、尚未发布的版本。
- **发布稿**：公开站点当前展示的版本。
- **源格式**：作者实际编辑的文本，`markdown` 或 `html`。
- **规范 HTML**：经过 Markdown 解析与白名单清洗后，可直接发布的 HTML。
- **优先级**：`P0` 阻断发布，`P1` 首版必须，`P2` 后续增强。

## 1. 执行摘要

编辑器不从“换一个更漂亮的输入框”开始，而是建立一条可验证的内容管线：

```text
作者输入
  → 格式适配器（Markdown / HTML / 写作态）
  → 解析与规范化
  → 服务端 HTML 清洗
  → 规范 HTML（发布稿）
  → 预览 / 保存 / 发布
```

核心决策：

| 编号 | 决策 | 理由 |
|---|---|---|
| ADR-001 | 发布稿继续存规范 HTML，Markdown 另存源文 | 公开渲染零改动，搜索、摘要、阅读时长不受影响 |
| ADR-002 | Markdown 与 HTML 分成两种明确模式 | 避免“Markdown 里混 HTML”带来的语义和安全歧义 |
| ADR-003 | 源码编辑器保持 Markdown/HTML 文本域，写作态用原生 contenteditable 与 Markdown 序列化 | 不引入额外富文本运行时，写作态与双源共用保存契约 |
| ADR-004 | 预览由服务端生成，复用保存管线 | 保证“预览所见 = 发布所得” |
| ADR-005 | 保存使用版本号做乐观锁 | 防止两个窗口互相覆盖 |
| ADR-006 | 自动保存只写工作稿，不直接改发布稿 | 避免输入过程中把半成品推到线上 |
| ADR-007 | 历史文章保持 HTML，不批量转 Markdown | 避免不可逆转换与标签损失 |
| ADR-008 | 不做命令面板 | 格式动作只通过常驻工具栏和选区工具条，减少浮层与快捷键状态 |
| ADR-008 | 首版不做暗色主题，但 token 预留主题映射 | 控制范围，保持后续可扩展 |

## 2. 目标与非目标

### 2.1 目标

- `ED-001` 同一篇文章可以在 Markdown 与 HTML 之间切换，不静默丢失源文。
- `ED-002` 编辑、预览、发布三处排版一致。
- `ED-003` 发布稿与工作稿分离，自动保存不污染线上内容。
- `ED-004` 所有高频操作可用键盘完成，符合 WCAG 2.2 AA 与 ATAG 2.0 的核心要求。
- `ED-005` 在 390px 手机、200% 缩放、纯键盘三种条件下都能完成写作、预览与保存。
- `ED-006` HTML 输入经过白名单清洗，Markdown 原始 HTML 默认不执行。
- `ED-007` 保存失败、网络中断、版本冲突都有明确恢复路径。

### 2.2 非目标

- 多人实时协作、CRDT、评论批注。
- 插件市场、自定义块、自由布局。
- 完整 DTP 排版、像素级画布。
- 跨站内容同步、第三方 CMS 导入。
- 首版暗色主题。

### 2.3 成功指标

| 指标 | 目标 |
|---|---|
| 新建文章到首次保存 | 中位 ≤ 45 秒 |
| 预览与发布排版差异 | 0 个已知差异 |
| 保存失败可恢复率 | 100%（本地草稿可找回） |
| 键盘完成核心流程 | 100% |
| 手机端核心流程完成率 | 100% |
| 编辑器 JS 增量 | ≤ 220 KB gzip |
| 预览接口 P95 | ≤ 300 ms（同机部署） |

## 3. 现状审计

### 3.1 当前链路

```text
editor.php（textarea）
  → PostAdminController::validate
  → PostAdminRepository::save
  → HtmlSanitizer::clean
  → posts.body（HTML）
  → post.php 直接输出
```

### 3.2 能力矩阵

| 能力 | 现状 | 影响 | 优先级 |
|---|---|---|---|
| Markdown 输入 | 不支持 | 技术文章只能手写 HTML | P0 |
| HTML 源码 | 支持但无辅助 | 标签、缩进、闭合全靠记忆 | P1 |
| 实时预览 | 无 | 发布前无法确认排版 | P0 |
| 格式标识 | 无 | 不知道正文以何种格式保存 | P0 |
| 自动保存 | 无 | 长文丢失风险高 | P1 |
| 保存状态 | 无 | 不知道是否成功 | P1 |
| 离开保护 | 无 | 误点导航即丢失 | P0 |
| 冲突检测 | 无 | 两个窗口会静默覆盖 | P1 |
| 表格 | 清洗器不支持 | Markdown 表格会被拆掉 | P1 |
| 代码语言标记 | 清洗器不支持 class | 代码块无法标注语言 | P1 |
| 大纲/目录 | 无 | 长文结构不可见 | P2 |
| 版本历史 | 无 | 无法回看和恢复 | P2 |
| 移动端编辑 | 侧栏挤压正文 | 手机上难以完成写作 | P0 |

### 3.3 现有代码边界

| 文件 | 当前职责 | 本次变化 |
|---|---|---|
| `themes/cream/templates/admin/editor.php` | 表单与 textarea | 重构为编辑器壳 |
| `plugins/admin/src/PostAdminController.php` | 校验、保存、预览 | 增加格式与版本协议 |
| `core/Content/PostAdminRepository.php` | 读写文章 | 增加格式字段读写 |
| `core/Content/PostRepository.php` | 公开读取 | 保持只读 `body` |
| `HtmlSanitizer.php` | HTML 白名单 | 扩展表格、代码语言、图片属性 |
| `public/assets/admin.css` | 后台样式 | 拆出编辑器样式 |
| `public/assets/cream.js` | AJAX 表单 | 增加离开保护与状态事件 |

## 4. 外部基准

| 产品/技术 | 学习点 | 取舍 |
|---|---|---|
| Ghost | 写作面优先、发布设置分离、卡片插入 | 不引入完整卡片生态 |
| Notion | 块结构、块悬浮操作 | 只借鉴块级排版，不做命令面板 |
| Bear | 专注模式、排版控制、标签自然 | 不复制其 Markdown 方言 |
| Obsidian / Typora | 源码与实时预览的连续感 | 预览改为服务端统一管线 |
| CodeMirror 6 | 模块化源码编辑、语法高亮、搜索、撤销 | 首版核心依赖 |
| CommonMark | 明确 Markdown 语义 | 原始 HTML 默认转义 |
| WCAG 2.2 / ATAG 2.0 | 键盘、焦点、创作工具可达性 | 作为验收门槛 |
| OWASP | 白名单清洗、URL 校验、CSP | 清洗器作为安全边界 |
| 设计系统实践 | 三层 token、状态矩阵、稳定尺寸 | 建立组件规范而非逐页调样式 |

### 4.1 六条设计原则

1. **写作面优先**：正文是主角，元数据在右侧或折叠区。
2. **格式明确**：Markdown / HTML / 写作态始终可见，不靠记忆。
3. **发布一致**：预览必须复用发布管线。
4. **状态常驻**：保存、冲突、离线、校验状态始终有反馈。
5. **切换有代价提示**：可能丢失格式时必须先说明。
6. **键盘与触控同等完整**：不是“能用鼠标就行”。

## 5. 内容模型与格式契约

### 5.1 双层内容

| 层 | 存储 | 可见性 | 用途 |
|---|---|---|---|
| 发布稿 | `posts.body` 等 | 公开 | 线上文章、搜索、摘要、Feed |
| 工作稿 | `post_drafts` | 仅后台 | 自动保存、未发布修改、冲突恢复 |
| 源文快照 | `body_source` | 仅后台 | Markdown 原文，保证可回编辑 |
| 历史版本 | `post_revisions` | 仅后台 | 发布快照、差异与恢复 |

首版可以先落地 `body_format` 与 `body_source`；工作稿表在第二阶段接入，但接口与状态机从首版预留。

### 5.2 `posts` 新增字段（首版）

| 字段 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `body_format` | `VARCHAR(12)` | `html` | `html` / `markdown` |
| `body_source` | `MEDIUMTEXT NULL` | `NULL` | Markdown 原文；HTML 模式为空 |
| `content_version` | `BIGINT UNSIGNED` | `1` | 每次成功保存自增；Phase 1 用它做发布稿乐观锁，Phase 2 工作稿另用 `base_version` |

迁移：`2026112103_add_editor_body_contract`，可逆，只增列不改旧正文。

### 5.3 `post_drafts`

| 字段 | 类型 | 说明 |
|---|---|---|
| `post_id` | `BIGINT UNSIGNED` | 主键，关联文章 |
| `title` / `excerpt` | `VARCHAR` / `TEXT` | 工作稿元数据 |
| `body_html` | `MEDIUMTEXT` | 工作稿规范 HTML |
| `body_format` | `VARCHAR(12)` | 工作稿格式 |
| `body_source` | `MEDIUMTEXT NULL` | 工作稿源文 |
| `base_version` | `BIGINT UNSIGNED` | 打开时基于的发布稿版本 |
| `draft_version` | `BIGINT UNSIGNED` | 工作稿自身版本，自动保存失败时用于冲突恢复 |
| `updated_at` | `DATETIME` | 自动保存时间 |

实现补充：工作稿同时保存 `slug`、`tags_json`、目标 `status`，避免只改标签或固定链接时无法进入工作稿。自动保存只写 `post_drafts`，不递增 `posts.content_version`；发布接口在事务内更新发布稿、同步标签并删除工作稿。

发布动作在一个事务内：写 `post_revisions` → 更新 `posts` → 清空或同步工作稿。

索引：`post_drafts` 以 `post_id` 为主键并索引 `updated_at`；`post_revisions` 以 `id` 为主键，唯一索引 `(post_id, version)`，普通索引 `(post_id, published_at)`。

### 5.4 Markdown 方言

采用 **CommonMark 0.31.2 + GFM 子集**：

| 语法 | 支持 | 输出 |
|---|---|---|
| 段落、换行 | 是 | `<p>` / `<br>` |
| `#` ~ `####` | 是 | 正文标题统一映射为 `<h2>`~`<h4>` |
| 粗体、斜体、删除线 | 是 | `<strong>` / `<em>` / `<del>` |
| 链接、图片 | 是 | `<a>` / `<img>`，URL 走白名单 |
| 引用 | 是 | `<blockquote>` |
| 无序/有序列表 | 是 | `<ul>` / `<ol>` / `<li>` |
| 代码块 | 是 | `<pre><code class="language-x">` |
| 表格 | 是 | `<table>`，对齐仅 `left/center/right` |
| 任务列表 | 是 | 只读勾选符号 + 文本，不输出可交互 input |
| 原始 HTML | 否 | 默认转义为文本；HTML 模式另行编辑 |
| 脚注、定义列表 | 暂不支持 | 列入 P2 |

规则：

- 文章标题是页面唯一 `H1`，正文 `#` 自动规范为 `H2`。
- Markdown 模式中的原始 HTML 不执行，避免“源码模式”和“HTML 模式”语义重叠。
- 代码围栏语言只允许 `[a-z0-9+#-]`，清洗后保留 `class="language-x"`。

### 5.5 HTML 白名单

| 类别 | 允许 |
|---|---|
| 块 | `p`、`br`、`hr`、`h2`、`h3`、`h4`、`blockquote`、`pre`、`figure`、`figcaption` |
| 列表 | `ul`、`ol`、`li` |
| 表格 | `table`、`thead`、`tbody`、`tr`、`th`、`td`、`caption` |
| 行内 | `strong`、`em`、`del`、`code`、`a`、`img`、`sup`、`sub` |
| 属性 | `href`、`src`、`alt`、`title`、`colspan`、`rowspan`、`align`、`class`（仅代码语言） |
| URL | `http`、`https`、`mailto`、站内 `/`、锚点 `#` |
| 禁止 | `script`、`style`、`iframe`、`object`、`embed`、表单控件、`svg`、`math`、事件属性、`javascript:` |

安全规则：

- 清洗在 Markdown 解析之后执行，解析器不作为安全边界。
- `class` 只允许 `language-[a-z0-9+#-]+`，防止任意样式注入。
- 图片必须有 `src`；`alt` 缺失产生 P1 警告，不阻断保存。
- 链接统一补 `rel="noopener noreferrer"`。
- 不允许 `data:` URL、内联样式、注释和 CDATA。

### 5.6 转换管线

```text
Markdown 源文
  → CommonMark/GFM 解析
  → HTML 片段
  → HtmlSanitizer
  → 规范 HTML
  → 保存到 posts.body / post_drafts.body_html

HTML 源文
  → HtmlSanitizer
  → 规范 HTML
```

转换规则：

- 保存 Markdown 时：`body_source` 保留原文，`body` 写规范 HTML。
- 保存 HTML 时：`body_source` 清空，`body_format=html`。
- 从 Markdown 切到 HTML：先提示“源文将不再保留”。
- 从 HTML 切到 Markdown：以当前 HTML 生成初始 Markdown，提示“复杂标签可能被简化”。
- 任何转换都不自动保存，必须由作者确认。

### 5.7 冲突协议

保存请求携带 `expected_version`（发布稿用 `content_version`，工作稿用 `base_version`）：

- 版本一致：保存成功，`content_version + 1`。
- 版本落后：返回 `409 conflict`，附带当前版本号、更新时间、标题摘要。
- 作者选择：`载入最新` / `覆盖保存` / `另存为副本`。
- 冲突未解决前禁用自动保存，保留本地草稿。

### 5.8 `post_revisions`

每次成功发布写入一份不可变快照，字段包括 `post_id`、`version`、标题、固定链接、摘要、标签、状态、正文格式、正文源文、规范 HTML、阅读时长、作者和发布时间。`version` 与发布后的 `content_version` 一致，`(post_id, version)` 唯一。

- 新建文章时记录 v1；之后每次发布在同一个事务内写快照、更新 `posts`、同步标签并删除工作稿。
- 升级时为现有文章回填当前版本，避免历史从零开始。
- 每篇保留最近 50 个版本，更早的快照在写入后清理。
- 历史页默认以线上版本为基准，也可选任意历史版本作为基准，展示字段变化和行级正文差异；恢复动作只创建工作稿，不直接修改线上文章。
- 恢复不删除任何历史快照；作者仍需经过内容健康检查并显式发布。

## 6. 编辑器架构

### 6.1 分层

```text
EditorShell（页面壳、动作、状态）
  ├─ DocumentHeader（标题、slug、标签）
  ├─ OutlineRail（结构导航）
  ├─ EditorStage
  │   ├─ SourceAdapter（CodeMirror 6）
  │   ├─ PreviewPane（服务端 HTML）
  │   └─ RichTextAdapter（写作态）
  ├─ PublishInspector（状态、摘要、检查）
  └─ StatusBar（字数、位置、保存状态）
```

### 6.2 适配器契约

```js
/**
 * 所有编辑模式实现同一份接口，保存与预览不依赖具体编辑器。
 */
const EditorAdapter = {
  mount(container, value, options) {},
  getValue() {},
  setValue(value) {},
  focus() {},
  destroy() {},
  on(event, handler) {}, // change / selection / focus / blur
};
```

约束：

- `getValue()` 永远返回当前模式的源文，不返回渲染后 HTML。
- 模式切换由 `EditorController` 协调，不由编辑器组件自己保存全局状态。
- `EditorController` 持有 `mode`、`format`、`dirty`、`version`、`saveQueue`。
- 格式动作由工具栏和选区工具条触发，统一写入 Markdown 源文。

### 6.3 保存状态机

| 状态 | 进入条件 | 允许动作 | 退出 |
|---|---|---|---|
| `clean` | 载入完成、无修改 | 编辑、预览 | 输入 → `dirty` |
| `dirty` | 内容变化 | 保存、预览、撤销 | 保存 → `saving` |
| `saving` | 保存请求发出 | 只读状态条 | 成功 → `saved`；失败 → `error` |
| `saved` | 保存成功 | 继续编辑 | 输入 → `dirty` |
| `error` | 网络/校验失败 | 重试、复制草稿 | 重试 → `saving` |
| `conflict` | 409 | 载入最新、覆盖、另存 | 解决后 → `saved`/`dirty` |
| `offline` | 网络断开 | 本地保存 | 恢复后 → `dirty` |

规则：

- 保存队列串行，同一时刻只有一个保存请求。
- 新输入不取消已发出的请求，但会让状态回到 `dirty`。
- 自动保存只更新工作稿；发布稿必须显式“发布”。
- 保存失败保留本地草稿，刷新后提示恢复。

### 6.4 预览契约

`POST /admin/posts/preview-body.html`

请求：

```json
{
  "format": "markdown",
  "source": "# 标题\n\n正文",
  "_csrf": "..."
}
```

响应：

```json
{
  "ok": true,
  "html": "<h2>标题</h2><p>正文</p>",
  "warnings": [],
  "headings": [{"level": 2, "text": "标题", "line": 1}],
  "stats": {"chars": 12, "words": 8, "reading_minutes": 1}
}
```

约束：

- 800ms 防抖；请求带 `AbortController`，新请求取消旧请求。
- 预览与保存共用服务端 `BodyRenderer`。
- 预览失败保留上一版结果，显示行内错误。
- 单次源文上限 1 MB，超限返回 `413`。
- 预览不写数据库，不做自动保存。

错误码：`400` 格式非法、`409` 版本冲突、`413` 源文过大、`422` 校验失败、`429` 频率过高。错误响应统一为 `{ok:false, code, message, field?}`。

### 6.5 自动保存矩阵

| 文章状态 | 首版 | 第二阶段 |
|---|---|---|
| 新建草稿 | 本地 `localStorage` 恢复 | 服务端工作稿自动保存 |
| 已发布文章 | 本地恢复，不自动改线上 | 工作稿自动保存，发布需显式确认 |
| 冲突 | 暂停自动保存 | 暂停并展示冲突面板 |
| 离线 | 本地保存 | 本地队列，恢复后同步 |

## 7. 信息架构

### 7.1 桌面布局

| 区域 | 宽度 | 内容 |
|---|---|---|
| 全局顶栏 | 100% | 品牌、后台导航、账号（唯一顶栏） |
| 页面标题行 | 100% | 返回、文档状态、模式切换、预览、保存 |
| 左栏 | 200px | 手稿目录、字数、写作提示 |
| 中央纸面 | `minmax(0, 1fr)` | 标题、标签、编辑区、状态条 |
| 右栏 | 288px | 发布检查（发布 / 检查 / 历史）、slug、摘要、内容健康、版本 |

规则：

- 不新增第二条全宽固定工具栏。
- 左栏在 1180px 以下隐藏；右栏在 980px 以下变为发布抽屉。
- 中央纸面最大 920px，正文最大行宽 38em。

### 7.2 手机布局

```text
全局顶栏
页面标题行（图标动作）
标题 / slug / 标签
模式切换
编辑区 / 预览（切换）
发布检查（折叠）
底部操作条（目录 / 预览 / 保存）
```

- 编辑与预览是分段切换，不做左右分栏。
- 底部操作条不遮挡焦点；聚焦元素滚动到可视区。
- 工具栏只保留模式与插入，格式按钮进“更多”。

### 7.3 页面层级

1. 文档状态：工作稿 / 已发布 / 有未发布修改 / 冲突。
2. 写作区：标题、正文、预览。
3. 发布区：状态、slug、标签、摘要、检查清单。
4. 辅助区：选区工具条、冲突面板、恢复提示。

## 8. 设计系统

### 8.1 Token 三层

```text
primitive（原始值）
  → semantic（语义角色：paper / ink / coral / line）
  → component（组件专用：button-primary-bg / editor-focus-ring）
```

不允许在组件里直接写十六进制色值或随意字号。

### 8.2 颜色

| 语义 token | 值 | 用途 |
|---|---|---|
| `paper` | `oklch(.998 .004 92)` | 纸面、编辑区 |
| `cream-0` | `oklch(.972 .016 92)` | 页面底色 |
| `cream-1` | `oklch(.988 .010 92)` | 次级表面 |
| `ink` | `oklch(.245 .012 70)` | 主文字、主按钮底 |
| `ink-soft` | `oklch(.390 .014 70)` | 正文 |
| `muted` | `oklch(.510 .012 78)` | 辅助信息 |
| `line` | `oklch(.885 .014 86)` | 分隔线 |
| `coral` | `oklch(.680 .135 32)` | 主操作、选中强调 |
| `butter` | `oklch(.905 .090 95)` | 当前行、提示、重点 |
| `mint` | `oklch(.875 .045 158)` | 成功、检查通过 |
| `sky` | `oklch(.890 .040 230)` | 链接、信息 |

规则：

- 珊瑚只用于主操作、当前模式、选中态；不大面积铺底。
- 黄油只用于高亮和提示，不用于正文背景。
- 正文对比度 ≥ 4.5:1；控件边界 ≥ 3:1。

### 8.3 字体角色

| 角色 | 字体 | 大小/行高 | 用途 |
|---|---|---|---|
| Display | 宋体栈 | 38–42 / 1.24 | 文章标题 |
| Title | 宋体栈 | 24–28 / 1.35 | 预览标题、区块标题 |
| Body | 系统无衬线 | 16–17 / 1.9 | 正文编辑 |
| UI | 系统无衬线 | 13 / 1.5 | 按钮、字段、导航 |
| Label | 系统无衬线 | 11 / 1.4 | 字段标签 |
| Mono | 等宽栈 | 13 / 1.75 | 源码、slug、统计 |
| Caption | 系统无衬线 | 10–11 / 1.5 | 辅助说明 |

### 8.4 间距、圆角、层级、动效

| 类别 | 值 |
|---|---|
| 间距 | 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 |
| 圆角 | 6（小控件）/ 10（按钮、字段）/ 14（卡片）/ 18（纸面）/ pill |
| 层级 0 | 无阴影，靠边线 |
| 层级 1 | `0 1px 2px ink/5%, 0 12px 30px -28px ink/25%` |
| 层级 2 | 纸面与浮层；不超过两层表面堆叠 |
| 动效 | 120ms 状态、180ms 展开、240ms 抽屉 |
| 缓动 | `cubic-bezier(.2,.8,.2,1)` |
| 减弱动效 | `prefers-reduced-motion` 下仅保留透明度 |

### 8.5 组件清单

| 组件 | 状态 | 说明 |
|---|---|---|
| `SaveStatus` | clean / saving / saved / error / conflict | 页头常驻 |
| `ModeSwitch` | write / markdown / html | 单一选中 |
| `FormatToolbar` | default / disabled / compact | 桌面完整，手机精简 |
| `SourcePane` | default / focus / error | CodeMirror 容器 |
| `PreviewPane` | loading / ready / empty / error | 与发布页一致 |
| `OutlineRail` | expanded / collapsed | 标题、正文、标签、发布 |
| `PublishInspector` | draft / published / dirty | 状态、字段、检查 |
| `Checklist` | pass / warn / block | 发布前检查 |
| `SelectionToolbar` | hidden / visible | 选区上方 |
| `ConflictBanner` | hidden / visible | 409 冲突 |
| `RecoveryPrompt` | hidden / visible | 本地草稿恢复 |
| `MobileActionBar` | edit / preview | 目录、预览、保存 |

### 8.6 组件状态矩阵

| 组件 | Default | Hover | Focus | Pressed | Disabled | Loading | Error |
|---|---|---|---|---|---|---|---|
| Button | 纸面/墨线 | 轻微加深 | 3px 珊瑚环 | 下移 1px | 不响应 hover | 宽度不变 | 珊瑚边 |
| Field | 1px line | line-strong | 珊瑚环 | — | 文本变灰 | 右侧 spinner | 珊瑚边 + 提示 |
| ModeSwitch | 纸面 | cream-0 | 焦点环 | — | 低透明 | — | — |
| ToolbarButton | 透明 | paper | 焦点环 | butter | 不响应 | — | — |
| Tag | cream-1 | paper | 焦点环 | — | — | — | — |

规则：状态变化不改变 padding、边框宽度与文字基线，避免布局跳动。

### 8.7 视觉约束

- 纸纹透明度 ≤ 0.3，只在页面底色与纸面上出现。
- 胶带、印章、手绘阴影是点缀，不是主要层级手段；一屏最多两处。
- 卡片最多两层表面：页面底色 → 纸面/侧栏，不再嵌套第三层卡片。
- 正文区不使用大面积色块；引用用左线 + 浅底。
- 源码语法色只做弱强调，标记符使用 `muted`。

## 9. 交互规范

### 9.1 模式切换

- 页头分段控件：`写作` / `Markdown` / `HTML`。
- 当前模式：珊瑚下划线 + 墨色文字；其他模式 `muted`。
- 切换前有未保存修改：先保存工作稿或提示。
- Markdown → HTML：确认“Markdown 源文将不再保留”。
- HTML → Markdown：提示“复杂标签可能被简化”，生成后可撤销。
- 写作态不可用时（旧 HTML 文章没有 Markdown 源文）保留按钮并禁用，同时说明原因。

### 9.2 工具栏

- 常驻：撤销、重做、标题、粗体、斜体、链接、引用、代码、插入。
- 选区工具条：粗体、斜体、链接、行内代码。
- 按钮 44×44 触控目标；桌面可用 32×32 视觉尺寸，点击区扩大。
- 图标按钮必须有 `aria-label` 与 `title`。
- 工具栏分组之间用 8px 间距，不滥用分隔线。

### 9.3 预览

- 桌面 58/42 分栏，可拖拽；也支持全屏预览。
- 手机编辑/预览切换。
- 预览头部显示模式、字数、同步状态。
- 预览与发布共用 `post-body` 样式。
- 预览为空时显示“还没有正文”，不显示空白纸。

### 9.4 保存与发布

- `Cmd/Ctrl + S` 保存工作稿。
- `Cmd/Ctrl + Shift + Enter` 打开发布确认，不直接发布。
- 发布前检查阻断项：空标题、空正文、slug 冲突、非法格式。
- 警告项：缺摘要、缺标签、图片缺 alt、标题层级跳跃。
- 发布按钮文案：`发布笔记`，成功后显示线上版本号和固定位置提示条。

### 9.5 专注模式

- `Cmd/Ctrl + Shift + F`。
- 隐藏左右栏、标签、摘要；保留标题、正文、保存状态。
- `Esc` 退出；退出后焦点回到正文。
- 手机专注模式进入全屏编辑，不显示底部发布区。

### 9.6 快捷键

| 动作 | macOS | Windows/Linux |
|---|---|---|
| 保存 | `Cmd+S` | `Ctrl+S` |
| 预览 | `Cmd+Shift+P` | `Ctrl+Shift+P` |
| 专注 | `Cmd+Shift+F` | `Ctrl+Shift+F` |
| 粗体/斜体 | `Cmd+B` / `I` | `Ctrl+B` / `I` |
| 链接 | `Cmd+K`（写作态选区） | `Ctrl+K` |
| 发布确认 | `Cmd+Shift+Enter` | `Ctrl+Shift+Enter` |
| 关闭工具条 | `Esc` | `Esc` |

### 9.7 内容健康检查

| 编号 | 检查 | 级别 | 处理 |
|---|---|---|---|
| CH-01 | 标题 4–60 字 | warning | 提示，不阻断 |
| CH-02 | 正文非空 | blocker | 阻断发布 |
| CH-03 | slug 合法且唯一 | blocker | 阻断保存/发布 |
| CH-04 | 标题层级从 H2 开始，不跳级 | warning | 提示 |
| CH-05 | 图片有 alt | warning | 提示，可确认发布 |
| CH-06 | 摘要 40–120 字 | warning | 提示 |
| CH-07 | 代码围栏成对 | blocker | 预览报错行 |
| CH-08 | 表格列数一致 | warning | 提示 |
| CH-09 | 外链可访问 | info | 不阻断，异步检查 |
| CH-10 | 阅读时长 > 0 | info | 自动计算 |

### 9.9 错误与恢复

| 场景 | 反馈 | 恢复 |
|---|---|---|
| 保存网络失败 | 状态条变珊瑚，保留输入 | 重试、复制草稿 |
| 409 冲突 | 横幅 + 三选一 | 载入最新/覆盖/另存 |
| 预览失败 | 预览区行内提示 | 保留上一版，重试 |
| 解析警告 | 行号 + 原因 | 跳到对应行 |
| 离开未保存 | 原生确认 | 保存后离开/放弃 |
| 本地草稿存在 | 页头提示 | 恢复/丢弃 |

## 10. 无障碍与包容性

标准：**WCAG 2.2 AA**，参考 **ATAG 2.0**。

| 编号 | 要求 | 验收 |
|---|---|---|
| A11Y-01 | 所有功能可键盘完成 | 不插鼠标完成新建→编辑→预览→保存 |
| A11Y-02 | 焦点可见且不被遮挡 | `:focus-visible`，2px 以上环，底部条不遮焦点 |
| A11Y-03 | 无键盘陷阱 | `Esc` 可从编辑器/面板返回文档 |
| A11Y-04 | 单字符快捷键可关闭/仅在焦点内 | `/` 只在编辑器聚焦时生效 |
| A11Y-05 | 触控目标 ≥ 24px，主操作 ≥ 44px | 手机实测 |
| A11Y-06 | 状态播报 | 保存状态 `aria-live=polite`，错误 `role=alert` |
| A11Y-07 | 对比度 | 正文 4.5:1，控件 3:1 |
| A11Y-08 | 200% 缩放可用 | 不重叠、不截断 |
| A11Y-09 | 减少动效 | `prefers-reduced-motion` |
| A11Y-10 | 输入法安全 | composition 期间不触发布局与格式快捷键 |
| A11Y-11 | 中文断行 | 正文 `line-break: strict`，不出现孤字 |
| A11Y-12 | 语义结构 | 标题层级、landmark、表单 label 完整 |

## 11. 性能预算

| 项目 | 预算 | 说明 |
|---|---|---|
| 编辑页 JS 增量 | ≤ 220 KB gzip | CodeMirror + Markdown/HTML 语言包 |
| 编辑页 CSS 增量 | ≤ 40 KB gzip | 编辑器样式 |
| 首次可输入 | ≤ 1.8 s | 中端手机、暖缓存 |
| 预览 P95 | ≤ 300 ms | 同机 PHP |
| 自动保存 P95 | ≤ 500 ms | 工作稿写入 |
| 10 万字文档内存 | ≤ 80 MB | 浏览器进程 |
| 布局偏移 | CLS ≤ 0.02 | 载入与状态切换 |
| 单文档软上限 | 200,000 字符 | 超出提示拆分 |
| 单文档硬上限 | 1 MB | 预览/保存拒绝 |

## 12. 安全与隐私

| 编号 | 规则 |
|---|---|
| SEC-01 | HTML 白名单清洗，Markdown 原始 HTML 默认转义 |
| SEC-02 | URL 仅允许 `http/https/mailto/站内路径/锚点`，拒绝 `data:` 与 `javascript:` |
| SEC-03 | 清洗后不得再拼接未清洗片段 |
| SEC-04 | CSRF 校验覆盖保存与预览 |
| SEC-05 | CSP 保持 `script-src 'self'`，编辑页不引入第三方脚本 |
| SEC-06 | 预览与发布同管线，前端不能提交已渲染 HTML 绕过清洗 |
| SEC-07 | 审计记录 `post.saved` / `post.published` / `editor.conflict` |
| SEC-08 | 不在 URL、日志、错误页泄露正文或凭据 |
| SEC-09 | 不使用第三方埋点；本地统计只记事件名与耗时 |
| SEC-10 | 依赖锁定版本，CI 跑安全审计 |

## 13. 测试矩阵

| 层 | 覆盖 | 工具/方式 |
|---|---|---|
| 单元 | 清洗器、Markdown 渲染、统计、slug | `tests/run.php` |
| 黄金样例 | 12 组 Markdown → HTML 快照 | 固定 fixture |
| 模糊测试 | 随机 HTML/XSS 载荷 | 本地脚本，禁止进入生产 |
| 集成 | 保存/预览/发布/冲突 | PHP + 临时库 |
| E2E | 新建、切换、保存、重开、手机 | 浏览器自动化 |
| 无障碍 | 键盘、焦点、对比度、缩放 | axe + 手工清单 |
| 性能 | bundle、预览、10 万字 | CI 预算与本地基准 |
| 迁移 | up/down、旧数据兼容 | MySQL 9.7 临时库 |
| 回滚 | 关闭编辑器开关回到旧页 | 配置切换 |

## 14. 发布与回滚

- 功能开关 `editor.v2`，默认仅管理员可见。
- 迁移只增列，不删旧字段；下一版本再清理。
- 发布前在测试快照跑完整矩阵。
- 线上保留旧编辑页路由 `?editor=classic` 一个版本周期。
- 回滚步骤：关闭开关 → 恢复旧模板 → 不动新增列。
- 监控事件：`editor.save_failed`、`editor.preview_failed`、`editor.conflict`、`editor.render_warning`。
- 失败阈值：保存失败率 > 1% 或冲突率 > 5% 时暂停灰度。

## 15. 分阶段计划

| 阶段 | 范围 | 退出条件 |
|---|---|---|
| Phase 0 | 规范、原型、迁移设计 | 本文档评审通过 |
| Phase 1 | `body_format/body_source/content_version`、Markdown/HTML 双源、服务端预览、新布局、本地恢复 | 双源往返不丢内容，60+ 测试全绿 |
| Phase 2 | `post_drafts`、自动保存工作稿、内容健康、发布门禁 | 自动保存不污染线上，冲突可恢复 |
| Phase 3 | 原生写作态、块级格式、选区工具条 | 写作态与双源共用保存契约 |
| Phase 4 | 版本历史、差异对比、恢复、协作预留 | 每次发布可追溯；快照、对比与恢复已落地 |

## 16. 验收条件

- `AC-01` 新建 Markdown 文章 → 保存 → 重新打开，`body_source` 与输入一致。
- `AC-02` 新建 HTML 文章 → 保存 → 重新打开，标签经清洗且结构稳定。
- `AC-03` Markdown 预览与发布页在标题、列表、引用、代码块、表格上无差异。
- `AC-04` 切换到 HTML 模式时出现源文丢失提示；取消后内容不变。
- `AC-05` 两个窗口先后保存，第二个收到 409，可载入最新或覆盖。
- `AC-06` 断网保存失败，刷新后可恢复本地草稿。
- `AC-07` 390px 手机可完成编辑、预览、保存，底部条不遮焦点。
- `AC-08` 纯键盘可完成新建→编辑→预览→保存。
- `AC-09` XSS 载荷不会出现在预览或发布页。
- `AC-10` 旧 HTML 文章打开后仍为 HTML 模式，不自动转 Markdown。

## 17. 风险与开放决策

| 风险 | 影响 | 缓解 |
|---|---|---|
| CodeMirror 打包体积 | 编辑页变慢 | 只加载编辑页，语言包按需 |
| Markdown 方言差异 | 预览与发布不一致 | 固定 CommonMark/GFM 版本与黄金样例 |
| 工作稿模型增加复杂度 | 保存链路变长 | 分阶段，首版本地恢复 |
| 富文本转换丢失 | 作者不信任 | 保留源文、转换前提示、可撤销 |
| 移动端键盘遮挡 | 写作体验差 | 底部条避开焦点、滚动到可视区 |
| 旧文章批量转换 | 不可逆 | 保持 HTML，不批量迁移 |

待确认：

1. 首版是否按 Phase 1 范围落地。
2. 新文章默认 Markdown 还是 HTML（建议 Markdown）。
3. Markdown 原始 HTML 是否保持“默认转义”（建议保持）。
4. 是否在 Phase 2 引入工作稿表与版本历史（建议是）。

## 18. 修订记录

| 版本 | 日期 | 内容 |
|---|---|---|
| v1 | 2026-11-21 | 首版双源编辑器方案、数据模型、布局与阶段计划 |
| v2 | 2026-11-21 | 增加工作稿/发布稿双层、冲突协议、状态机、预览契约、Markdown 方言、HTML 白名单、组件状态矩阵、三层 token、WCAG/ATAG、性能预算、测试矩阵与 AC |

## 参考

- WCAG 2.2：https://w3c.github.io/wcag/guidelines/22/
- ATAG 2.0 中文：https://www.w3.org/Translations/ATAG20-zh/
- OWASP XSS 预防：https://cheatsheetseries.owasp.ac.cn/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
- OWASP HTML Sanitizer：https://owasp.org/www-project-java-html-sanitizer/
- CodeMirror 6：https://codemirror.net/docs/
- CommonMark：https://spec.commonmark.org/0.31.2/
- Ghost Editor：https://ghost.org/help/editing-posts/
- VA Design System 组件状态：https://design.va.gov/about/designers/creating-components
