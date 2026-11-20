# 归档页

公开归档按时间倒序整理已发布文章，入口在桌面顶栏和手机目录的“归档”。

## 地址

| 地址 | 内容 |
|---|---|
| `/archive.html` | 归档总览：按年份汇总，年份里再列月份和篇数 |
| `/archive/{yyyy}.html` | 年归档：按月份分组，列出文章标题 |
| `/archive/{yyyy}/{mm}.html` | 月归档：文章卡片，每页 20 篇 |
| `/archive/{yyyy}/{mm}/page/{n}.html` | 月归档第 n 页 |

`{mm}` 必须补零，例如 `/archive/2026/11.html`；写成 `/archive/2026/11` 也会由伪静态解析到同一路由。`/archive/2026/11/page/1.html` 会 301 到月归档第一页，避免同一内容出现两个 200 地址。

## 数据规则

- 只统计 `status = published` 且发布时间已到的文章。
- 草稿、未来文章和后台“已归档”状态不会出现在公开归档。
- 年份按数据库中的发布时间计算，排序为年份倒序、月份倒序、文章发布时间倒序。
- 月归档每页 20 篇；超出总页数返回 404，不返回空 200。
- 年归档最多展示 500 篇标题，避免一次渲染过多内容。

## 页面与 SEO

- 总览、年归档、月归档都是 `CollectionPage` JSON-LD，并输出 canonical。
- 月归档第 1 页 canonical 为 `/archive/{yyyy}/{mm}.html`；第 2 页起为 `/archive/{yyyy}/{mm}/page/{n}.html`。
- 归档页是只读页面，不启动会话；成功页面显式声明 5 分钟公共缓存，避免每次访问都打数据库。
- 页面通过 `posts.css` 和 `archive.css` 渲染，移动端月网格按可用宽度自动收成一到两列，文章行隐藏右侧箭头。

## 错误处理

- 年份不是四位数字、月份不在 1–12、页码非法：404。
- 该年或该月没有已发布文章：404。
- 数据库不可用：503，页面给出稍后再试的提示。
- 归档页不会因为分页越界返回空列表，避免被搜索引擎抓取无限页。

## 相关代码

- `core/Content/PostRepository::archiveMonths()`：年份和月份的聚合。
- `core/Content/PostRepository::publishedInPeriod()`：按年月取文章。
- `core/Content/PostRepository::countPublishedInPeriod()`：分页总数。
- `plugins/posts/src/ArchiveController.php`：路由、校验、canonical 与模板数据。
- `themes/cream/templates/archive.php`、`archive-year.php`、`archive-month.php`：三套页面模板。
