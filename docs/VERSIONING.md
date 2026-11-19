# 版本规范

本文件是 `version.json`、发布物和数据库迁移的唯一操作口径。设计蓝图 7.13 与本文件冲突时，以本文件为准，再同步回蓝图。

## 0. 交付前基线

正式交付前，应用版本固定从 `0.1.0` 起算，使用 `MAJOR.MINOR.PATCH` 三段式。日常修复不逐次升 patch，只在可交付里程碑升 minor；交付前的内部迭代编号不再作为对外版本。

## 1. 五个版本轴

| 轴 | 格式 | 唯一来源 | 规则 |
|---|---|---|---|
| 应用 | SemVer `MAJOR.MINOR.PATCH` | `version.json` 的 `app` | 破坏性升 major，加功能升 minor，修 bug 升 patch |
| 数据库结构 | 单调整数 `YYYYMMDDNN` | `schema_meta.schema_version` | 只增不减；每次结构变更一个版本 |
| API | SemVer | `public/docs/openapi.yaml` 的 `info.version` | `/api/v1` 只做加法；破坏性变更开 `/api/v2` |
| 插件 / 主题 | SemVer | `plugin.json` / `theme.json` | 与内核解耦，但必须声明 `requires` |
| 页面包 | SemVer | `content/pages/{slug}/page.json` | 页面代码/样式变更，不写数据库结构 |

应用版本和 Git tag 必须一致：tag 使用 `v{app}`，例如 `v0.6.0`。`tools/lint-version.php --tag=v0.6.0` 是发布门禁。

## 2. version.json

```json
{
  "app": "0.6.0",
  "channel": "stable",
  "schema": 2026112004,
  "schema_min": 2026100101,
  "api": "1.1.0",
  "php": ">=8.5.0",
  "mysql": ">=9.7.4",
  "plugins": {},
  "themes": {}
}
```

- `schema` 是本次发布期望的结构版本。
- `schema_min` 是这套代码仍能运行的最低结构版本。
- 数据库实际结构在 `[schema_min, schema]` 之间允许启动；高于 `schema` 返回 `schema-ahead`，低于 `schema_min` 返回 `schema-behind`。
- 启动只读检查，不自动迁移。迁移只允许 CLI 和首启安装器执行。

## 3. 发布物

每次发布生成不可变目录：

```text
releases/v{app}/
├── RELEASE_MANIFEST.json
├── version.json
├── public/
├── core/
├── plugins/
├── themes/
├── database/migrations/
├── vendor/
└── ...
current  -> releases/...
previous -> releases/...
```

`current`、`previous` 是软链；切换用临时软链加 `rename()`，不直接删除旧链接。

`RELEASE_MANIFEST.json` 至少包含：

```json
{
  "release_id": "v0.6.0",
  "app": "0.6.0",
  "channel": "stable",
  "schema": 2026112004,
  "api": "1.1.0",
  "commit": "a1b2c3d",
  "built_at": "2026-11-20T07:30:00Z",
  "artifact_sha256": "…",
  "files_sha256": {
    "version.json": "…",
    "public/index.php": "…"
  }
}
```

升级前必须校验 manifest、文件哈希、PHP/MySQL 版本；目标 release 的 `version.json` 必须与 manifest 的 `app/schema` 一致。

### 3.1 构建发布目录

```bash
php bin/release.php build --id=v0.6.0 --out=/srv/cream/releases/v0.6.0 --json
php bin/release.php verify --dir=/srv/cream/releases/v0.6.0 --json
```

- `--id` 必须等于 `v{version.json.app}` 或 `{app}`；输出目录必须不存在或为空。
- 默认排除 `.env`、`storage/`、`.git`、`vendor/`、缓存和部署软链；不会打包密钥或用户上传。
- 需要自包含发布时加 `--with-vendor`；否则在目标机执行 `composer install --no-dev --classmap-authoritative`。
- 生成 `RELEASE_MANIFEST.json`，记录每个文件的 SHA-256、`artifact_sha256`、commit 和构建时间。
- `verify` 只读校验 manifest 与文件哈希；发布目录生成后不可修改，任何改动都发新版本。

发布目录就位后：

```bash
ln -s releases/v0.6.0 current
php bin/upgrade.php status --json
```

升级只操作已经存在的 release 目录；不会从网络下载代码。

### 3.2 部署根与共享存储

生产目录把可变数据留在发布目录之外：

```text
/var/www/cream/
├── .env                 # 0600，共享密钥与数据库配置
├── storage/             # 安装锁、上传、日志、备份、升级状态
├── releases/v0.6.0/
├── current -> releases/v0.6.0
└── previous -> releases/v0.6.0
```

- 发布目录没有 `.env` 时，入口会向上查找 `../.env` 和 `../../.env`；仍建议把 `.env` 放在部署根。
- `STORAGE_DIR` 指向共享 `storage`；媒体、登录节流、安装锁、日志和升级状态都跟随该路径。
- 安装锁在共享 `storage/installed.lock` 时，`/install.php` 返回 404；入口文件保留以维持 manifest 校验。
- `bin/backup.sh` 同样向上查找 `.env`，并用 `STORAGE_DIR` 备份上传文件。
- Nginx `root` 指向 `current/public`，不要指向 release 绝对路径。
- 切换 `current` 后必须 reload PHP-FPM/OPcache，否则 realpath 缓存可能继续服务旧 release。


## 4. 迁移文件

```php
<?php
declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112004,
    name: 'create_audit_logs',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute('CREATE TABLE IF NOT EXISTS ...');
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS ...');
    },
);
```

规则：

- 一个迁移只做一件可命名的事；文件名以 `{version}_` 开头。
- 必须可重入：`IF NOT EXISTS`、先查后建、回填幂等。
- 已应用迁移的 `checksum` 被改，`plan` 直接失败，禁止偷偷修改历史。
- MySQL DDL 隐式提交，多语句迁移不是事务；失败即停、保留现场，靠备份/可重入恢复。
- 不能回退的迁移标 `irreversible: true`，并删除 `down`；`plan` 会显示 `point_of_no_return`。
- 插件迁移 `scope=plugin:{id}`，主题迁移 `scope=theme:{id}`；禁用插件不自动删表。

## 5. Expand / Contract

| 阶段 | 发布 | 做什么 | 可回退方式 |
|---|---|---|---|
| expand | N | 加新表/新列，双写，旧代码仍能跑 | `release` / `down` |
| migrate | N+1 | 切读新结构，回填，继续双写 | `down`（若回填可逆） |
| contract | N+2 | 删旧列/旧表，只读新结构 | 只能 `restore` |

不在一个 release 里“加列 + 改读 + 删旧列”。回填走 `jobs` 分批，幂等，不在迁移里全表 `UPDATE`。

## 6. 发布门禁

```bash
php tools/lint-version.php --tag=v0.6.0
php tools/lint-migrations.php
php tests/run.php
php bin/release.php verify --dir=/srv/cream/releases/v0.6.0
```

CI 必须检查：

- `version.json` 的 `app` 与 tag 一致。
- `schema` 是 10 位 `YYYYMMDDNN`，`schema_min <= schema`。
- 最高 core 迁移版本等于 `version.json.schema`。
- 迁移文件名、scope、checksum、`up/down` 成对关系正确。
- 发布包不包含 `.env`、`storage` 备份/日志/上传、`.git`；`vendor` 默认不打包，按部署方式决定。

## 7. 兼容与发布纪律

- 版本发布后内容不可变；任何修改发新版本，不覆盖旧 release。
- `MAJOR` 不兼容；`MINOR` 兼容新增；`PATCH` 兼容修复。
- 预发布只允许 `beta` / `rc` 通道；主应用正式版不带预发布后缀。
- `/api/v1` 只加字段、加端点；删除字段或改语义开 `/api/v2`。
- 版本号不写进 `composer.json` 根包；唯一来源是 `version.json`。
