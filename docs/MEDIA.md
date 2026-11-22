# 媒体库

## 后台入口

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| GET | `/admin/media.html` | 媒体列表、筛选、概览与上传区 |
| POST | `/admin/media.html` | 上传 1–5 张图片 |
| POST | `/admin/media/{id}/rename.html` | 修改显示名称 |
| POST | `/admin/media/{id}/delete.html` | 删除单张图片记录与文件 |
| POST | `/admin/media/bulk-delete.html` | 批量删除，最多 100 张 |
| GET | `/media/{id}/{name}` | 受控读取图片 |

后台必须登录，所有写操作必须带 `_csrf`。

## 列表与筛选

- `q`：匹配显示名称或图片编号，最多 80 字。
- `type`：`jpg`、`png`、`webp`、`gif`，对应数据库 MIME 精确匹配。
- `shape`：`landscape` 横图、`portrait` 竖图、`square` 方图；方图允许宽高相差 5%。
- `weight`：`small`（100KB 以下）、`medium`（100KB–1MB）、`large`（1MB 以上）。
- `period`：`today`（24 小时内）、`week`（7 天内）、`month`（30 天内）。
- `sort`：`newest`、`oldest`、`largest`、`name`。
- `page`：固定每页十张，翻页时保留筛选条件。
- 顶部概览显示总数、磁盘占用和最近上传时间；格式下拉带各格式数量。
- 筛选区是独立纸感卡片，与文章、标签、日志页一致。
- 手机端沿用后台统一筛选：默认只显示“筛选图片”按钮，展开后搜索占一整行，其余条件按三列排布。

## 上传契约

- 字段名：`file[]`，`multipart/form-data`，`multiple` 选择，一次最多 5 张。
- 单张大小：大于 0 且不超过 5MB；批量中某张失败不影响其余图片。
- 服务器 PHP 必须把 `upload_max_filesize` 和 `post_max_size` 调到 12M，Nginx `client_max_body_size` 同为 12m；否则请求会在进入应用前被拒绝。
- 类型：JPEG、PNG、WebP、GIF，以服务端 `finfo` 为准，不信任扩展名。
- 尺寸：`getimagesize` 校验，宽高 1–10000。
- 拒绝 SVG、HTML、PHP、压缩包等非图片。
- 原文件名只用于显示，磁盘名由 32 位随机十六进制 + 扩展名组成。
- 显示名称可单独重命名，不影响磁盘文件名和公开地址。

## 交互

- 卡片支持选择、全选本页、批量删除；选中后卡片出现“已选”标记，屏幕底部立即浮出操作条。
- “链接”和“HTML”直接复制到剪贴板，兼容不支持 Clipboard API 的浏览器。
- 点图片或“详情”打开详情层，包含大图、格式、尺寸、字节数、上传时间、重命名、地址、HTML 片段、打开原图与删除；不支持原生 `<dialog>` 的浏览器会自动降级为普通浮层。
- 图片网格在桌面按可用宽度自动铺列，手机固定两列。

## 存储与读取

```text
storage/uploads/2026/11/{32位随机}.png
```

- 上传目录不在 Web 根下，不能直接通过 URL 访问。
- 数据库 `media` 表记录磁盘名、原名、MIME、大小、宽高、上传人。
- 公开地址由 `MediaController` 查库、校验 `realpath`、读取文件后输出。
- 响应带 `Content-Type`、`X-Content-Type-Options: nosniff` 和一年不可变缓存。
- 删除图片会同时删除数据库记录和磁盘文件；删除文章不会删除图片。
- 批量删除在一个事务内完成数据库删除，事务成功后再移除文件；文件删除失败不会恢复记录。

## API

需要 `media:read` 或 `media:write` 作用域，详见 [API 规范](API.md) 与 `docs/openapi.yaml`。

| 方法 | 路径 | 作用域 |
| --- | --- | --- |
| GET | `/api/v1/admin/media` | `media:read` |
| GET | `/api/v1/admin/media/{id}` | `media:read` |
| POST | `/api/v1/admin/media` | `media:write` |
| PATCH | `/api/v1/admin/media/{id}` | `media:write` |
| DELETE | `/api/v1/admin/media/{id}` | `media:write` |
| POST | `/api/v1/admin/media/bulk` | `media:write` |

## Nginx

`deploy/nginx.conf.example` 已有 `location / { try_files $uri /index.php; }`，`/media/...` 会走 PHP。不要把 `storage/uploads` 配成静态目录，也不要在上传目录开启 PHP 执行。

## 孤儿清理

```bash
php bin/media-clean.php --json                 # 试运行
php bin/media-clean.php --confirm --json       # 清理 24 小时前的孤立文件
php bin/media-clean.php --age-hours=72 --json  # 调整时间窗口
```

只扫描 `storage/uploads` 下不在 `media` 表中的文件；目录和已知文件不会被删除。

## 尚未实现

- 图片裁剪、WebP 自动转换、缩略图。
- 文章引用检测与“未被使用”筛选。
- 上传配额与按管理员分配空间。
