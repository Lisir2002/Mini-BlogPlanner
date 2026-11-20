# Mini-BlogPlanner

「日记Planner」的博客内核。原生 PHP、插件化、主题化，适合长期写作、个人日记与内容管理。

> 项目名：**Mini-BlogPlanner**　站点名：**日记Planner**

## 特性

- 原生 PHP 微内核，无 Twig、Laravel 等重型框架
- 插件化路由与主题模板，公开页和后台共用仓储
- 文章、标签、全站搜索、独立页面、媒体库、评论、赞踩回声与审计
- 评论先审后发，带 CSRF、蜜罐、频率限制与重复拦截
- 完整错误页、`.html` 伪静态、响应式布局
- 带哈希清单的发布包，支持升级、回退与数据库备份

## 环境

- PHP 8.5+
- MySQL 9.7+
- 扩展：`pdo_mysql`、`mbstring`、`json`

## 安装

```bash
composer install
php -S 127.0.0.1:8099 -t public public/router.php
```

打开 `http://127.0.0.1:8099/install.php`，按向导填写数据库、站点名和管理员信息。安装完成后入口自动关闭。

已有部署可复制 `.env.example` 为 `.env`，配置数据库后执行：

```bash
php bin/migrate.php
curl http://127.0.0.1:8099/healthz
```

## 验证

```bash
php tools/lint-version.php
php tools/lint-migrations.php
php tools/lint-core.php
php tests/run.php
vendor/bin/phpstan analyse --no-progress
```

## 发布

```bash
php bin/release.php build --id=v0.1.2 --out=/tmp/mini-blogplanner-v0.1.2 --with-vendor
php bin/release.php verify --dir=/tmp/mini-blogplanner-v0.1.2
```

升级与回退：

```bash
php bin/upgrade.php status
php bin/upgrade.php plan --to=v0.1.2
php bin/upgrade.php apply --plan=<plan_id> --confirm
```

## 目录

```text
core/       微内核：版本、数据库、HTTP、路由、插件
plugins/    web / api / posts / pages / admin / media / install
themes/     cream 主题与后台模板
public/     唯一 Web 根
database/   迁移
docs/       安装、升级、API、品牌与安全文档
bin/        版本、迁移、发布、备份 CLI
tests/      契约测试
```

## 文档

- [安装](docs/INSTALL.md)
- [升级与回退](docs/UPGRADE.md)
- [文章回声](docs/REACTIONS.md)
- [搜索](docs/SEARCH.md)
- [版本规范](docs/VERSIONING.md)
- [品牌规范](docs/BRAND.md)
- [安全边界](docs/SECURITY.md)
- [API](docs/API.md)

## 许可

MIT
