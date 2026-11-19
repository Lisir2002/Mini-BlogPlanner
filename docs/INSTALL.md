# 安装器

## 入口

- Web：`/install.php`，仅在 `storage/installed.lock` 不存在时可访问。
- CLI 重装：后续 `bin/install-reset.php --force`；Web 安装成功后入口不可用。
- 入口自禁用：成功后保留 `public/install.php` 但由 `installed.lock` 逻辑关闭；再次访问返回 404。保留文件是为了不破坏发布 manifest。

## 六步

| 步骤 | 内容 | 完成条件 |
| --- | --- | --- |
| environment | PHP >= 8.5、扩展、目录、vendor、版本文件、维护状态、磁盘 | 无阻断项 |
| database | 主机/端口/库名/账号；可选建库；MySQL >= 9.7.4 | 连接成功且字符集可用 |
| admin | 用户名、邮箱、密码 | 12 位以上，含字母和数字；Argon2id 哈希 |
| site | 标题、描述、Base URL、时区、语言、主题 | URL/时区/枚举合法 |
| run | 再次输入数据库密码，执行迁移与写入 | 全部步骤成功 |
| finish | 展示命令、入口状态、管理员 | 仅 POST 后可进入 |

## 共享存储

release 部署时把 `STORAGE_DIR` 指向部署根的 `storage`，安装器会：
- 在共享存储写 `installed.lock`；
- 把 `.env` 中的 `STORAGE_DIR`、`DEPLOY_ROOT` 保留；
- 若 `DEPLOY_ROOT` 存在，把 `.env` 副本写到部署根，供后续 release 回退加载；
- 保留 `public/install.php`，安装后由锁文件返回 404，避免破坏发布清单。

## 安全契约

- **安装锁**：`flock storage/install.lock` 独占；并发请求返回 409。
- **CSRF**：安装会话内随机 token；缺失或不匹配返回 419。
- **限流**：默认 10 次 POST / 分钟 / IP，超限 429。
- **HTTPS**：非 loopback 地址必须 HTTPS，否则 403；loopback 允许 HTTP 开发。
- **密码**：数据库密码只在当前请求内存中使用；不写会话、不写日志。执行前在确认页重新输入。
- **管理员密码**：只保存 Argon2id 哈希；不写会话明文。
- **`.env`**：临时文件写入后 `rename` 替换，权限 0600；拒绝包含换行的值。
- **锁文件**：`storage/installed.lock` 0640，内容含安装时间、app、schema。
- **失败**：不写 `installed.lock`，入口仍可用；已执行的迁移保留，重试会跳过。
- **日志**：`storage/logs/install.log` 记录事件、IP 哈希、上下文；不记录密码。

## 数据库探测

- 拒绝 `root` / `admin` 账号。
- 可选 `CREATE DATABASE IF NOT EXISTS`；库名只允许字母数字下划线。
- 字符集 `utf8mb4`，排序规则 `utf8mb4_0900_ai_ci`。
- `ngram_token_size` 仅探测，不是 2 时给出警告，不阻断。
- 报错按 MySQL 错误码转成可读文案，不把 SQL 错误直接回显。

## 上传上限

应用允许单张图片最大 5MB，PHP-FPM 与 Nginx 必须留出 multipart 开销：

```ini
; /etc/php/8.5/fpm/conf.d/99-cream-upload.ini
upload_max_filesize = 12M
post_max_size = 12M
```

```nginx
client_max_body_size 12m;
```

改完执行 `systemctl reload php8.5-fpm` 与 `systemctl reload nginx`。安装器的环境检查只给警告，不会替用户修改系统配置；默认 `2M` 会让 2MB 以上的合法图片在进入应用前被拒绝。

## 验收命令

```bash
curl -I https://example.com/install.php          # 安装后应为 404
stat -c '%a %n' .env                             # 600
curl -fsS https://example.com/healthz
php bin/version.php --json
php bin/upgrade.php status --json
bin/backup.sh --check --json
```

## 已知边界

- 安装器本身依赖 `vendor/autoload.php`；部署时必须先 `composer install --no-dev`。
- 测试机 PHP 8.1 会被环境检查阻断，这是预期行为；真实目标 PHP 8.5。
- 安装成功后入口由锁文件关闭；不要把 `installed.lock` 放进公开目录。
