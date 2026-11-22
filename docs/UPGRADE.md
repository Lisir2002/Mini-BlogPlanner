# 升级与回退操作契约

升级和回退的核心操作仍由 CLI + 服务器登录权限完成。后台只提供受控的一键更新，通过固定 sudo 入口调用同一套 CLI；不提供任意命令输入或后台回退按钮。

## 1. 命令

```bash
php bin/upgrade.php status --json
php bin/upgrade.php plan --to=<release_id> --json
php bin/upgrade.php apply --plan=<plan_id> --confirm
php bin/upgrade.php resume --plan=<plan_id>
php bin/upgrade.php verify --release=<release_id> --json
php bin/upgrade.php rollback --mode=release --to=<release_id> --confirm
php bin/upgrade.php rollback --mode=down --to=<release_id> --confirm
php bin/upgrade.php rollback --mode=restore --backup=<backup_id> --to=<release_id> --confirm
```

`plan` 只写 `storage/upgrade/plan-*.json`，不改数据库、不切软链、不动维护标志。`apply` 要求 `--confirm`。

发布目录由 `bin/release.php` 生成并放在 `releases/v{app}`；`current` 指向当前 release。`backup` 步骤用 `/bin/bash` 执行 `bin/backup.sh`，生产机必须能调用 `mysqldump`；非标准安装通过 `MYSQL_DUMP_BIN`、`MYSQL_BIN`、`LD_LIBRARY_PATH` 注入，不把密码写进命令参数。

无结构变更的标准流程：

```bash
php bin/release.php verify --dir=/srv/cream/releases/v0.5.1 --json
php bin/upgrade.php plan --to=v0.5.1 --json
php bin/upgrade.php apply --plan=<plan_id> --confirm --json
php bin/upgrade.php status --json
php bin/upgrade.php rollback --mode=release --confirm --json
```

回退成功与否看 `status` 的 `current`、`previous`、`schema`，不看命令行提示。

切换 `current` 是软链原子替换；PHP-FPM/OPcache 的 realpath 缓存可能继续解析旧目录。配置 `RUNTIME_RELOAD_CMD`（例如 `systemctl restart php8.5-fpm`）后，工具会在切换后自动执行并等待 0.5 秒，再检查 `/healthz`；未配置时必须手动 restart PHP-FPM；只 reload、重启 CLI 或重启 Nginx 都可能继续命中旧 realpath。后台一键更新会先把助手放进独立 systemd 临时服务，因此 PHP-FPM 重启不会中断后续健康检查和状态收尾。

## 2. plan 输出

```json
{
  "ok": true,
  "plan_id": "pln_20261120_153000_7f3a1b2c",
  "from": {"app": "0.6.0", "schema": 2026100101, "release": "v0.5.0"},
  "to": {"app": "0.6.0", "schema": 2026112004, "release": "v0.6.0"},
  "migrations": [
    {"scope": "core", "version": 2026112001, "name": "create_audit_logs", "reversible": true, "irreversible": false}
  ],
  "plugins": [],
  "themes": [],
  "backup_required": true,
  "maintenance_required": true,
  "rollback_modes": ["down", "restore"],
  "point_of_no_return": null,
  "warnings": ["升级期间站点进入维护模式"]
}
```

`plan_id` 由当前 release、目标 release、当前结构版本、目标结构版本哈希生成。目标 release 或结构变化后，旧 plan 失效，必须重新 plan。

`plan` 必须拒绝：

- 目标 release 的 manifest、文件哈希、`version.json` 不一致。
- 目标 app 低于当前 app，或目标 schema 低于当前 schema。
- 当前 `schema_meta` 缺失。
- 已应用迁移 checksum 被修改。
- 当前没有 `current` release。

## 3. 状态机

```text
preflight -> backup -> maintenance -> deploy -> migrate -> warmup -> switch -> smoke -> done
```

| 步骤 | 做什么 | 失败处理 |
|---|---|---|
| preflight | 校验 release、结构、磁盘、锁、维护状态 | 不改状态，exit 2 |
| backup | `bin/backup.sh` 生成 DB dump、uploads 清单、manifest、checksum | 中止，站点不动，exit 4 |
| maintenance | 写 `storage/maintenance.flag` | 站点 503 |
| deploy | 校验目标 release 的 manifest 和 `version.json` | 旧 release 仍是 current |
| migrate | 按 scope/version 执行，逐条记账 | 失败即停，exit 5；用 resume 或 rollback |
| warmup | 清 `storage/cache` | 旧代码仍可服务 |
| switch | 原子切换 `current`，旧 current 写 `previous` | 失败可切回；配置 `RUNTIME_RELOAD_CMD` 时自动 restart PHP-FPM |
| smoke | 校验 `current`、`version.json`、`/healthz` | exit 6；保持维护模式和当前 release，按回退模式人工处置 |
| done | 清维护标志，写审计 | — |

状态文件 `storage/upgrade/state.json` 记录 `plan_id/step/status/release/previous_release/backup_id/schema_before/schema_after/migrations_applied/error/started_at/updated_at`。

`migrate` 在 `switch` 前执行，前提是迁移遵循 expand/contract：只加结构、不删旧结构，旧代码在切换前仍能跑。不是 expand 型的迁移必须改成 expand/contract，或接受更长维护窗口。

## 4. resume

```bash
php bin/upgrade.php resume --plan=<plan_id>
```

- 只接受同一个 `plan_id`；当前 release 或 schema 变了，拒绝。
- 从 `state.step` 重跑；每步先检查是否已完成，完成则跳过。
- 已成功迁移不会被重复执行；失败迁移必须可重入。
- 进程硬崩后 `state.status` 可能是 `running`；拿到升级锁后允许 resume，另一个进程拿不到锁则 exit 3。

## 5. 三种回退

| 模式 | 适用 | 数据库动作 | 数据影响 |
|---|---|---|---|
| `release` | 本次升级没有执行任何迁移 | 无，只切 `current` 回目标 release | 无 |
| `down` | 本批次迁移全部 reversible，且没有 irreversible | 按逆序执行 `down()`，结构降到目标版本，再切 release | 取决于 down 是否正确 |
| `restore` | 有不可逆迁移，或 down 失败 | 恢复升级前 DB 备份，再切 release | 备份之后的数据会丢 |

编辑器迁移 `2026112103` 的 `down` 会把 Markdown 源文、正文格式和版本号暂存到 `posts_editor_rollback_backup`，再删除三列；重新升级时从备份表恢复并删除该表。降级期间源文不会丢，但旧版本代码不读取这张备份表。

工作稿迁移 `2026112104` 新建 `post_drafts`，只存未发布的编辑内容。`down` 会直接删除该表，尚未发布的工作稿会丢失，线上文章不受影响；回退前应确认没有需要保留的工作稿，或先做数据库备份。

版本快照迁移 `2026112105` 新建 `post_revisions`，并为现有文章回填当前版本。`down` 会删除历史快照，线上文章与工作稿不受影响；回退后版本历史页不可用。

API 令牌迁移 `2026112106` 新建 `api_tokens`，只存令牌哈希、前缀、作用域和有效期。`down` 会删除令牌表，已签发的令牌全部失效，需要重新创建。

回退前置条件：

- 升级锁已拿到，维护模式已开。
- 目标 release 存在、manifest 与文件哈希通过。
- `release` 要求目标 schema 等于当前 schema；不等就拒绝，exit 8。
- `down` 要求目标 schema 低于当前 schema；任一迁移 `irreversible` 或没有 `down` 就拒绝。
- `restore` 要求有效 `backup_id`；先校验 checksums，再恢复。
- 回退后实际 schema 必须落在目标 release 的 `[schema_min, schema]`；否则留在维护模式，exit 8。

`restore` 顺序：

```text
维护 -> 校验备份 -> 恢复 DB -> 切回旧 release -> 清缓存 -> 校验 schema -> 关维护
```

上传文件不跟着数据库回退。旧文件仍在，孤儿文件由清理任务处理；不要为了“干净”删上传目录。

## 6. 备份与恢复

```bash
bin/backup.sh --reason=pre-upgrade --json
bin/backup.sh --check --json
php bin/restore.php --backup=<backup_id> --confirm --json
```

备份目录包含：

```text
backups/{backup_id}/
├── manifest.json
├── db.sql
├── version.json
├── schema_meta.tsv
├── uploads.tar.gz   # 可选
└── checksums.txt
```

`bin/restore.php` 只恢复数据库，不覆盖 uploads；恢复前必须 `--confirm`，并验证 `checksums.txt`。保留策略：每日 7 份、每周 4 份、每次升级 1 份；升级备份不参与自动轮转，直到下一次升级成功 + 7 天。

## 7. 退出码

| 码 | 含义 |
|---|---|
| 0 | 成功 |
| 1 | 通用错误 / 未知命令 |
| 2 | preflight 失败 |
| 3 | 升级锁被占用，或状态不可 resume |
| 4 | 备份失败 |
| 5 | 迁移失败 |
| 6 | smoke 失败 |
| 7 | 回退失败 |
| 8 | 版本/结构不兼容 |
| 9 | plan 缺失、过期或与当前状态不匹配 |

所有命令支持 `--json`，输出 `{ok, code, message, data}`。人读模式不打印 SQL、密码、备份路径细节。

## 8. SchemaGuard 响应

| 实际结构 | 响应 |
|---|---|
| `> version.json.schema` | 503 `schema-ahead`，先升级代码 |
| `< version.json.schema_min` | 503 `schema-behind`，先跑升级 |
| `[schema_min, schema]` | 正常启动，后台显示待迁移数 |
| `schema_meta` 不存在 | 未装走安装器；已装 503 `schema-missing` |

Web 请求永远不自动迁移。`SchemaGuard` 只读检查；自动迁移会把“打开网页”变成“改生产数据库”。

## 9. 审计

文件日志：`storage/logs/upgrade.log`，事件：

- `system.upgrade.started/resumed/migrated/succeeded/failed`
- `system.rollback.succeeded`
- `system.schema.guard_blocked`

数据库可用后，同一事件同时写 `audit_logs` 表。审计不记录密码、DB 连接串、完整备份路径。

## 10. 验收清单

1. 无迁移升级后 `release` 回退，首页/文章/API 正常。
2. 加列 expand 后 `down` 回退，结构回到目标版本。
3. 删列 contract：`plan` 标记 `point_of_no_return`，只允许 `restore`。
4. 迁移中途失败：`state=failed`，旧 release 仍在，维护未关；`resume` 从失败点继续。
5. smoke 失败：维护模式保持开启；无结构变更用 `rollback --mode=release`，有结构变更用 `restore`。
6. schema 高于代码：`/` 与 `/api/v1/site` 返回 503 `schema-ahead`。
7. 并发升级：第二个进程 exit 3，不写状态。
8. 备份失败：在 maintenance 前中止。
9. 迁移 checksum 被改：plan 拒绝，exit 8/9。
10. 插件/主题/页面回退各自通过，数据库不被隐式删除。


## 11. 已验证场景

- 隔离部署中准备 `releases/0.5.0` 与 `releases/0.5.1`（同 schema），`plan -> apply -> status -> rollback --mode=release` 全流程通过。
- 升级后 `current` 指向 `0.5.1`、`previous` 指向 `0.5.0`；`status` 的 `app` 读取 current release，不再误报根目录版本。
- 回退后 `current` 与 `previous` 互换，schema 保持 `2026112004`，维护标志已清理。
- `backup` 步骤实际生成 `bkp_*`，验证了 `/bin/bash` 调用路径；缺少 `mysqldump` 时在维护模式前中止。
- 发布清单逐文件哈希；篡改 `version.json` 后 `bin/release.php verify` 返回 1 并指出文件名。
