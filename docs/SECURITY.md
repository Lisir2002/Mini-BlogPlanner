# 安全基线

本文件只记录已经落地的边界；未落地的项明确标注，不把计划当现状。

## 1. 传输与响应头

- 生产只开 HTTPS；HTTP 301 到 HTTPS。
- HSTS 由 Nginx 输出：`max-age=31536000; includeSubDomains`。
- 应用统一输出：
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- 未启用 CSP：后台和主题模板仍有内联事件/样式，直接上严格 CSP 会破坏页面。后续改为 nonce + 外置脚本后再加。

## 2. 认证与会话

- 管理员密码用 Argon2id 校验；登录成功 `session_regenerate_id(true)`。
- 会话 Cookie 为 HttpOnly、SameSite=Lax；HTTPS 环境加 Secure。
- 空闲 7200 秒过期；写操作全部校验 CSRF。
- 登录失败按 IP 限流：15 分钟窗口，第 6 次返回 429。
- 后台未登录一律 303 到登录页；数据库不可用返回 503，不泄露连接信息。

## 3. 内容与上传

- 文章正文经白名单过滤，移除脚本、事件属性、iframe、表单和危险 URL 协议。
- 上传只允许 JPEG/PNG/WebP/GIF，大小 0–5MB，服务端 `finfo` + `getimagesize` 双重校验。
- 上传文件存 `storage/uploads`，权限 0640，不在 public 下；读取走 `/media/{id}/{name}`，校验 realpath 边界。
- 拒绝 PHP、SVG、HTML 上传；原始文件名不参与磁盘路径。
- 删除媒体先校验路径在上传目录内；审计失败不回滚删除结果。
- 评论正文按纯文本处理，入库与输出都转义；IP 只保存带 `APP_KEY` 的 HMAC，不保存明文。
- 评论提交校验 CSRF、表单停留时间、蜜罐、同 IP 频率和重复正文；新留言默认待审核。

## 4. 伪静态与路由

- `.html` 由 Router 去后缀，磁盘静态文件优先；未知 `.html` 才进 front controller。
- Nginx `location ^~ /media/` 必须在静态资源正则之前，避免 `/media/1/x.png` 被当成磁盘文件 404。
- `/storage/`、`/vendor/`、点文件在 Nginx 层拒绝。
- 安装完成后 `installed.lock` 生效，`public/install.php` 保留但返回 404；保留文件是为了不破坏发布 manifest。

## 5. 发布与升级

- 发布目录不可变，`RELEASE_MANIFEST.json` 逐文件 SHA-256；`bin/release.php verify` 是上线门禁。
- 升级/回退只走 CLI，后台不提供 apply/rollback POST。
- 升级前强制数据库备份；备份失败在维护模式前中止。
- 回退分 `release`、`down`、`restore`，有不可逆迁移只能恢复备份。
- 密钥只放部署根 `.env` 或进程环境，不写入 release、不提交仓库、不进日志。

## 6. 已知缺口

- 无 CSP nonce、无多管理员角色权限、无二步验证。
- 审计保留期清理需手动执行 `bin/audit-prune.php`，尚未接入定时任务。
- 无媒体缩略图/WebP 转换和上传配额。
- 无真实 HTTPS/Nginx 集成测试；`deploy/nginx.conf.example` 是基线，不是已验收配置。
