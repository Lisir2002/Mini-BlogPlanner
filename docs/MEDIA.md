# 媒体库

## 入口

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| GET | `/admin/media.html` | 媒体列表与上传表单 |
| POST | `/admin/media.html` | 上传一张图片 |
| GET | `/media/{id}/{name}` | 受控读取图片 |
| POST | `/admin/media/{id}/delete.html` | 删除图片记录与文件 |

后台必须登录，上传必须带 `_csrf`。

## 上传契约

- 字段名：`file`，`multipart/form-data`。
- 大小：大于 0 且不超过 5MB。
- 服务器 PHP 必须把 `upload_max_filesize` 和 `post_max_size` 调到 12M，Nginx `client_max_body_size` 同为 12m；否则图片会在进入应用前被 PHP 拒绝，错误会变成笼统的“上传失败”。
- 类型：JPEG、PNG、WebP、GIF，以服务端 `finfo` 为准，不信任扩展名。
- 尺寸：`getimagesize` 校验，宽高 1–10000。
- 拒绝 SVG、HTML、PHP、压缩包等非图片。
- 原文件名只用于展示和下载名，磁盘名由 32 位随机十六进制 + 扩展名组成。

## 存储与读取

```text
storage/uploads/2026/11/{32位随机}.png
```

- 上传目录不在 Web 根下，不能直接通过 URL 访问。
- 数据库 `media` 表记录磁盘名、原名、MIME、大小、宽高、上传人。
- 公开地址由 `MediaController` 查库、校验 `realpath`、读取文件后输出。
- 响应带 `Content-Type`、`X-Content-Type-Options: nosniff` 和一年不可变缓存。
- 删除文章不会删除图片；孤儿文件由后续清理任务处理。

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
- 媒体重命名、批量上传。
- 上传配额与存储用量统计。
