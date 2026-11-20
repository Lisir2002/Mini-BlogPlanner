# 系列页

公开系列把同一段旅程里的文章串起来，入口在桌面导航、手机目录和文章页的系列标记。

## 地址

| 地址 | 内容 |
|---|---|
| `/series.html` | 系列总览：列出有已发布文章的系列 |
| `/series/{slug}.html` | 系列详情：按发布时间从早到晚列出文章 |

`{slug}` 只接受小写字母、数字和单个短横线，最长 80 位；非法或没有已发布文章的系列返回 404。

## 数据规则

- 一篇文章只属于一个系列，通过 `posts.series_id` 关联。
- 只统计 `status = published` 且发布时间已到的文章；草稿、归档和未来文章不进入公开系列。
- 系列详情按 `published_at` 从早到晚排列，先发布的是第一篇。
- 公开列表最多渲染 200 篇，避免单页过大。
- 系列总览按最近一篇已发布文章的时间倒序排列。
- 系列名与 slug 在创建时确定；编辑只改系列名和说明，slug 保持不变，避免公开地址失效。

## 后台

```text
GET  /admin/series.html
POST /admin/series.html
POST /admin/series/{id}.html
POST /admin/series/{id}/delete.html
```

- 新建时可以留空 slug，系统会按中文名称生成；也可以自己填英文短横线地址。
- 只有没有文章的空系列可以删除；已有文章时先把文章移出系列。
- 文章编辑器右侧增加“系列”下拉框，选择“不加入系列”即可移出。
- 系列名不能重复；说明最多 500 个字符。

## 页面与 SEO

- `/series.html` 和系列详情都输出 `CollectionPage` JSON-LD 与 canonical。
- 总览和详情写入 sitemap；文章页显示“系列”标记，点击进入系列详情。
- 系列公开页是只读页面，不启动会话；成功页面声明 5 分钟公共缓存。
- 页面通过 `posts.css` 与 `series.css` 渲染；手机端系列卡和文章时间线收成单列。

## 数据库

迁移 `2026112101_create_series`：

- 新建 `series` 表，保存名称、slug、说明与时间。
- 给 `posts` 增加 `series_id`、`idx_series (series_id, published_at)` 和外键；删除系列时文章保留，关联置空。

## 相关代码

- `core/Content/SeriesRepository.php`：系列列表、详情、文章归属和排序。
- `plugins/posts/src/SeriesController.php`：公开总览、详情、canonical 与 404。
- `plugins/admin/src/SeriesAdminController.php`：后台系列增删改与审计。
- `themes/cream/templates/series.php`、`series-detail.php`：前台模板。
- `themes/cream/templates/admin/series.php`：后台管理页。
