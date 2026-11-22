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
- 登录失败按 IP 限流：15 分钟窗口最多 20 次，超出返回 429。
- 后台未登录一律 303 到登录页；数据库不可用返回 503，不泄露连接信息。
- 管理 API 使用 `Authorization: Bearer mbp_<token>`；令牌只存 SHA-256 哈希，明文只在创建成功时显示一次。
- 令牌按 `status:read`、`content:read`、`comments:read`、`comments:write`、`tags:read`、`tags:write` 授权；缺少、无效、过期、撤销返回 401，作用域不足返回 403。
- 令牌可设有效期并随时撤销；后台与审计只记录前缀，不记录完整值；令牌不得放进 URL、前端代码或公开日志。

## 3. 内容与上传

- 文章正文经白名单过滤，移除脚本、事件属性、iframe、表单和危险 URL 协议。
- 上传只允许 JPEG/PNG/WebP/GIF，大小 0–5MB，服务端 `finfo` + `getimagesize` 双重校验。
- 上传文件存 `storage/uploads`，权限 0640，不在 public 下；读取走 `/media/{id}/{name}`，校验 realpath 边界。
- 拒绝 PHP、SVG、HTML 上传；原始文件名不参与磁盘路径。
- 删除媒体先校验路径在上传目录内；审计失败不回滚删除结果。
- 评论正文按纯文本处理，入库与输出都转义；IP 只保存带 `APP_KEY` 的 HMAC，不保存明文。
- 评论提交校验 CSRF、表单停留时间、蜜罐、同 IP 频率和重复正文；新留言默认待审核。
- 后台与 API 的审核、撤回、标为垃圾和删除都校验权限并写审计；API 批量最多 100 条，列表不返回邮箱，删除父留言级联删除回复。

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

## 6. 限流与抗压

- Cloudflare 回源时，Nginx 只信任官方地址段发送的 `CF-Connecting-IP`，不信任任意 `X-Forwarded-For`。
- Nginx 对动态页面、API、登录、POST 和媒体分别限速：一般 30r/s、API 15r/s、登录 5r/s、写请求 8r/s、媒体 50r/s；单 IP 并发连接上限 60，超限返回 429。
- 应用在路由前执行固定窗口限流：公开页面 300 次/分钟/访客、后台 600 次/分钟/访客、公开 API 300 次/分钟/IP、管理 API 240 次/分钟/令牌加 600 次/分钟/IP、登录 POST 60 次/5 分钟/IP、留言 10 次/分钟/访客加 40 次/分钟/IP、互动 30 次/分钟/访客加 60 次/分钟/IP、全站 1200 次/分钟/IP。
- 页面、后台、留言、互动和写请求优先用会话 Cookie 区分访客，IP 仅作高上限兜底，降低公司、校园和运营商共享出口的误判。
- 管理 API 同时按 IP 和令牌哈希计数，令牌被滥用时可单独撤销；限流键不保存明文 IP 或令牌。
- 限流计数优先使用本机 SysV 共享内存，避免每次请求写数据库；共享内存不可用时回退到带文件锁的本地计数。
- 超限响应为 429，带 `Retry-After`、`X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset` 和 `X-RateLimit-Scope`。
- 这些措施降低应用层刷量和慢连接影响，但不能替代 CDN、带宽清洗和机房防火墙。
- Nginx 对动态请求设置 5/10/20 秒的 FastCGI 连接、发送、读取超时，避免慢上游长期占用 PHP-FPM；安全响应头由网关统一补齐。
- 80 端口的域名跳转和默认站点也纳入单 IP 限速；默认站点只跳到正式域名，不再通过服务器 IP 直接暴露应用。
- 接口文档页和 OpenAPI 源文件只允许管理员会话访问，响应带 `no-store` 与 `noindex`；公开静态路径 `/api.html`、`/docs/` 在 Nginx 层返回 404。

## 7. 已知缺口

- 无 CSP nonce、无多管理员角色权限、无二步验证。
- 审计保留期清理需手动执行 `bin/audit-prune.php`，尚未接入定时任务；后台审计页只读，并提供带脱敏的 CSV 导出。
- 无媒体缩略图/WebP 转换和上传配额。
- 无真实 HTTPS/Nginx 集成测试；`deploy/nginx.conf.example` 是基线，生产已按同目录模板配置并通过 `nginx -t`。
- 应用限流为单机共享内存，部署多台 PHP 节点时需改为共享存储或每节点独立计数。
