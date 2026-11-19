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

- 昵称 2–40 字；邮箱可空，填写时校验格式；正文 2–1000 字。
- 正文入库前 `strip_tags`，模板输出统一转义。
- 必须带 CSRF；表单带会话时间戳，停留 3 秒至 2 小时有效。
- 蜜罐字段 `website` 有值直接拒绝。
- 同一 IP 哈希 10 分钟最多 5 条；5 分钟内相同正文视为重复。
- 新留言一律 `pending`，提交后回到文章页 `#comments`，提示等待审核。

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

公开页样式在 `public/assets/comments.css`，后台在 `public/assets/admin.css` 的留言审核段。输入框、文本域、按钮沿用主题自绘样式，不使用原生下拉、文件选择器或浏览器默认按钮。

## 本地验证

```bash
php bin/migrate.php
php tests/run.php
```

测试站已跑通：表单时效、提交、待审核、回复、通过、标垃圾、删除、重复拦截、蜜罐、IP 限流、CSRF、未登录后台和不存在文章。
