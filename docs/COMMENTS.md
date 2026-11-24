# 评论功能

文章页的留言与单层回复。评论不依赖第三方服务，数据存在 `comments` 表；公开页只显示已通过审核的留言，后台负责审核。

## 数据

迁移 `2026112005_create_comments.php` 建立 `comments`：

- `post_id`：所属文章，外键到 `posts`。
- `parent_id`：回复的父留言，只允许一层；外键到 `comments`。
- `author_name`、`author_email`、`body`：昵称、可选邮箱、纯文本正文。
- `status`：`pending`、`approved`、`spam`。
- `ip_hash`：`APP_KEY` 与 IP 的 HMAC-SHA256，用于限流和去重，不保存明文 IP。
- `user_agent`、`approved_at`、`approved_by`：审计与审核记录。

删除父留言会级联删除回复。

## 提交规则

- 昵称 2–40 字；邮箱可空，填写时校验格式；正文 2–1000 字；一条留言最多 6 个贴图标记。
- 正文入库前 `strip_tags`，模板输出统一转义。
- 必须带 CSRF；表单带会话时间戳，停留 3 秒至 2 小时有效。
- 蜜罐字段 `website` 有值直接拒绝。
- 同一 IP 哈希 10 分钟最多 5 条；5 分钟内相同正文视为重复。
- 新留言一律 `pending`，提交后回到文章页 `#comments`，提示等待审核。

## 表情输入

面板分三个一级类，每类下再按用途分小类；一级类不混排，避免同一屏里同时出现文字、动图和字符表情。

| 一级类 | 作用 | 二级小类 |
| --- | --- | --- |
| Emoji | 一句话里顺手补语气，纯文本、跨设备、成本最低；共 6 组 48 项 | 回应、心情、鼓励、日常、符号、祝愿 |
| 贴图 | 用动图接住整句情绪，视觉重音；共 4 组 36 项，只提供内置固定包 | 回应、情绪、日常、氛围 |
| 颜文字 | 不依赖图片的字符表情，适合长评论、纯文本 API 和低带宽；共 6 组 48 项 | 元气、温柔、无语、躺平、得意、抓狂 |

- 三个一级类固定并列；小类横向切换；切换分类时头部、最近条和一级类保持原位，只替换下方内容。
- 所有一级类合计 132 项。Emoji 使用四列网格；贴图保持三列；颜文字保持两列，长表达式不会被截断。
- 内容区有固定最大高度并独立滚动，小类标签保持可见；切换小类会回到该组顶部，不会继承上一组的滚动位置。
- 最近使用固定在三个一级类上方，跨类混排，最多 8 项；最近不是第四个大类。
- Emoji 直接插入 Unicode，颜文字直接插入纯文本，贴图插入中文短标记，例如 `[贴图:点赞]`。
- 正文仍是纯文本，不新增数据库字段，不新增附件表，也不改变审核、限流和 API 契约。公开留言、回复和后台审核卡片统一通过 `cream_comment_body()` 渲染；只有白名单名称会映射到 `public/assets/stickers/{slug}.gif`，未知标记保留原文，不执行任何用户 URL 或任意图片路径。
- 一条留言最多 6 个贴图标记；总正文仍受 1000 字上限约束。面板插入前会检查标记数量，服务端再次校验。
- 最近使用只保存在当前浏览器的 `localStorage`，键为 `cream.comment.expression.recent.v1`，最多 8 项；旧键 `cream.comment.emoji.recent.v1` 会兼容读取并迁移。
- 面板支持两级标签的键盘方向键、Home/End 和 Escape 关闭；插入后回到正文光标，不自动提交。
- 贴图使用 `<picture>`：正常加载 72×72 GIF，`prefers-reduced-motion: reduce` 时加载 96×96 静态 PNG；图片懒加载、空 alt 配合外层 `role="img"` 的可读标签，固定宽高避免布局跳动。
- 不提供访客上传、Giphy/Tenor 搜索或任意图片地址。内置素材来自 Google Noto Emoji 动画资源，共 36 张动图和 36 张静态替代图，许可为 CC BY 4.0；来源、修改方式和逐项映射记录在 `public/assets/stickers/README.md`，贴图面板底部显示署名与许可链接。

## 路由

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| POST | `/posts/{slug}/comments` | 提交留言或回复 |
| GET | `/admin/comments` | 后台列表，`status=pending|approved|spam` 筛选 |
| POST | `/admin/comments/{id}/approve` | 通过审核 |
| POST | `/admin/comments/{id}/spam` | 标为垃圾 |
| POST | `/admin/comments/{id}/delete` | 删除，回复一并删除 |

后台操作全部校验 CSRF，并写 `comment.approved`、`comment.spam`、`comment.deleted` 审计事件。

## 样式

公开页样式在 `public/assets/comments.css`，后台审核集中在 `public/assets/admin-comments.css`。输入框、文本域、按钮沿用主题自绘样式，不使用原生下拉、文件选择器或浏览器默认按钮。

## 本地验证

```bash
php bin/migrate.php
php tests/run.php
```

测试站已跑通：表单时效、提交、待审核、回复、通过、标垃圾、删除、重复拦截、蜜罐、IP 限流、CSRF、未登录后台和不存在文章。
