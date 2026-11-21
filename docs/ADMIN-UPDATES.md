# 后台版本与一键升级

## 目标

后台提供一个只对管理员开放的“版本与升级”页。页面显示当前应用、后台插件、主题和数据库结构版本，读取 GitHub 上最新的正式 Release，并在管理员确认后完成下载、校验、备份、迁移、切换和健康检查。

日常发布流程保持为：本地只做检查与提交，推送 `v*` tag，GitHub Actions 云端构建并上传 Release。之后不再需要登录服务器手工解压，后台点击更新即可。

## 信任边界

- 后台页面运行在 `www-data`，只读取版本、发布目录和任务状态，不直接写 `releases`、`current` 或数据库备份。
- 真正修改部署目录的更新助手由 root 执行，只允许 `www-data` 通过一条固定的 sudo 规则调用，不能传入任意命令或任意下载地址。
- 仓库地址固定为本项目 GitHub 仓库，Release 标签必须是稳定版 `v<major>.<minor>.<patch>`。
- 更新助手只接受 GitHub API 返回的资产地址，不接受页面传入 URL。
- Release zip 必须同时满足 SHA256 校验、文件清单逐项校验、版本契约和结构校验，才允许进入升级计划。
- 后台请求必须已经登录并通过 CSRF；更新开始后，页面轮询任务状态，不重复派发任务。

## 组件

| 组件 | 位置 | 职责 |
| --- | --- | --- |
| 发布目录读取 | `Core\Upgrade\ReleaseRepository` | 只读 `releases`、`current`、`previous` 与清单 |
| GitHub 发布目录 | `Core\Upgrade\ReleaseCatalog` | 读取最新稳定 Release、匹配 zip 与 sha256 资产 |
| 安全解压 | `Core\Upgrade\ReleaseArchive` | 拒绝路径穿越、绝对路径、符号链接和异常体积 |
| 更新任务 | `Core\Upgrade\UpdateJob` | 原子写入任务状态，供页面轮询 |
| 后台控制器 | `Plugin\Admin\UpdatesAdminController` | 页面、检查更新、派发任务、状态接口 |
| root 助手 | `bin/update-helper.php` | 下载、校验、解压、生成计划并执行升级 |
| 固定入口 | `/usr/local/bin/cream-update` | root 所有，转发到当前 release 的助手 |

## 一键更新流程

1. 页面读取本地版本和 GitHub 最新 Release，比较语义化版本。
2. 管理员确认后，控制器校验 CSRF、管理员身份、任务状态和目标 tag。
3. 控制器写审计事件，并通过受限 sudo 在后台启动助手。
4. 助手重新查询 GitHub Release，确认目标没有变成预发布版或已撤回。
5. 下载 zip 与 `.sha256`，校验哈希、文件大小与文件名。
6. 在临时目录安全解压，校验 `RELEASE_MANIFEST.json` 与全部文件哈希。
7. 把验证通过的目录原子移动到 `releases/<tag>`。
8. 调用现有 `bin/upgrade.php plan` 生成计划，再执行 `apply`：备份、维护模式、迁移、清缓存、切换软链、健康检查。
9. 任务状态写为 `done` 或 `failed`，页面轮询到终态后刷新。

## 状态机

`queued → checking → download → verify → install → plan → apply → done`

任一步失败写入 `failed`，保留 `step`、`error`、`plan_id` 和 `backup_id`。已有升级锁或未完成的升级状态时，助手拒绝启动新任务。

## 安全约束

- 目标版本必须大于当前版本，禁止降级。
- 目标 Release 必须是非草稿、非预发布、稳定通道。
- 解压文件数不超过 3000，单文件不超过 50MB，总解压体积不超过 200MB。
- 拒绝 `..`、绝对路径、反斜杠、Windows 盘符、符号链接和 `__MACOSX`。
- 已存在的目标 release 目录不会被覆盖。
- 安装前检查至少 1GB 磁盘空间。
- 升级失败不会删除备份；现有 `upgrade.php` 的恢复与回退命令仍然可用。
- 页面不显示任何密钥、数据库密码、环境变量内容或完整服务器路径中的敏感部分。

## 回退

一键更新只负责向前升级。失败时页面显示错误和 `plan_id`；管理员可使用现有命令：

```bash
php bin/upgrade.php status --json
php bin/upgrade.php rollback --mode=restore --backup=<backup_id> --confirm --json
```

## 部署前提

生产服务器需要安装固定入口和 sudo 规则：

```bash
install -o root -g root -m 0755 deploy/cream-update /usr/local/bin/cream-update
echo 'www-data ALL=(root) NOPASSWD: /usr/local/bin/cream-update' > /etc/sudoers.d/cream-update
chmod 0440 /etc/sudoers.d/cream-update
visudo -cf /etc/sudoers.d/cream-update
```

固定入口每次执行当前 release 中的 `bin/update-helper.php`，因此后续版本更新助手逻辑时不需要再次手工替换入口。
