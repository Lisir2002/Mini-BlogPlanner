# 全站搜索

搜索只覆盖已发布文章：状态为 `published`，且 `published_at` 不晚于当前时间。草稿、归档和定时发布的文章不会出现在结果里。

## 页面

```text
GET /search?q=关键词
```

- 空查询：返回搜索页和输入提示，不查数据库。
- 两个及以上字符：走 `posts` 表的 `FULLTEXT` 索引，索引字段为 `title`、`excerpt`、`body`，解析器为 `ngram`。
- 单个字符：退回标题、摘要、正文的 `LIKE` 包含匹配，避免 ngram 对单字无结果。
- 排序：标题命中权重高于摘要，其次按全文相关度和发布时间倒序。
- 结果最多 20 条；搜索页响应带 `X-Robots-Tag: noindex, follow`，不生成 canonical，也不进 sitemap。

搜索词会去掉标签、压缩连续空白并截到 60 个字符。空查询和超长查询都不会触发数据库报错。

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

- 搜索只读，不记录查询词和访客身份。
- 正文中的 HTML 标签不参与展示；结果摘取正文里的命中片段，找不到命中片段时使用文章摘要。
- 索引随文章发布、更新和删除自动维护，不需要单独重建任务。
