# 全站搜索

搜索只覆盖已发布文章：状态为 `published`，且 `published_at` 不晚于当前时间。草稿、归档和定时发布的文章不会出现在结果里。

## 入口

公开站点不再提供独立搜索页。首页顶栏的搜索按钮（移动端在侧边栏按钮左侧）打开一层悬浮蒙版，输入框和结果都在蒙版里完成，不跳转页面。

- 输入两个字：防抖后请求 `/api/v1/search`，结果在蒙版内即时显示。
- 单个字符：提示继续输入，不发请求，避免单字结果过宽。
- 支持 `↑` / `↓` 选择结果、`Enter` 打开、`Esc` 关闭，以及 `Ctrl/Cmd + K` 或 `/` 快捷打开。
- 最近搜索只保存在浏览器本地存储，最多 5 条，可一键清除。
- 兼容旧地址：`GET /search?q=关键词` 302 跳到 `/?search=关键词`，由蒙版读取查询词后立即清理地址参数。

搜索词会去掉标签、压缩连续空白并截到 60 个字符。空查询和超长查询都不会触发数据库报错。

两个及以上字符走 `posts` 表的 `FULLTEXT` 索引，索引字段为 `title`、`excerpt`、`body`，解析器为 `ngram`。单个字符退回标题、摘要、正文的 `LIKE` 包含匹配。排序为标题命中权重高于摘要，其次按全文相关度和发布时间倒序，结果最多 20 条。

## 索引

迁移 `2026112007_add_post_search_index.php`：

```sql
ALTER TABLE posts
  ADD FULLTEXT KEY ft_posts_search (title, excerpt, body)
  WITH PARSER ngram;
```

MySQL 的 `ngram_token_size` 为 2 时中文效果最好。安装向导会检查该值；不是 2 时给出警告，但搜索仍可用，单字查询走 `LIKE` 回退。

## 接口

```text
GET /api/v1/search?q=关键词
```

- 空查询返回 `422 /problems/search-query-required`。
- 成功返回 `data` 文章摘要列表和 `meta.query`、`meta.count`。
- 响应带 `Cache-Control: no-store`。
- 数据库不可用返回 `503 /problems/service-unavailable`。

## 边界

- 搜索只读，服务端不记录查询词和访客身份；最近搜索只在当前浏览器本地保存。
- 正文中的 HTML 标签不参与展示；接口返回文章摘要，蒙版内只使用文本节点和 `mark` 高亮，不拼接 HTML。
- 索引随文章发布、更新和删除自动维护，不需要单独重建任务。
