<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112103,
    name: 'add_editor_body_contract',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
ALTER TABLE posts
  ADD COLUMN body_format VARCHAR(12) NOT NULL DEFAULT 'html' AFTER body,
  ADD COLUMN body_source MEDIUMTEXT NULL AFTER body_format,
  ADD COLUMN content_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER reading_minutes
SQL);

        // 降级时源文列会被删除；先建空备份表，使全新安装也能安全执行恢复语句。
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS posts_editor_rollback_backup (
  post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  body_format VARCHAR(12) NOT NULL,
  body_source MEDIUMTEXT NULL,
  content_version BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        $db->execute(<<<'SQL'
UPDATE posts p
JOIN posts_editor_rollback_backup b ON b.post_id = p.id
SET p.body_format = b.body_format,
    p.body_source = b.body_source,
    p.content_version = b.content_version
SQL);

        $db->execute('DROP TABLE IF EXISTS posts_editor_rollback_backup');
    },
    down: static function (ConnectionInterface $db): void {
        // 回滚前保留编辑源文与版本，避免降级后重新升级丢失 Markdown 草稿。
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS posts_editor_rollback_backup (
  post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  body_format VARCHAR(12) NOT NULL,
  body_source MEDIUMTEXT NULL,
  content_version BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        $db->execute(<<<'SQL'
REPLACE INTO posts_editor_rollback_backup (post_id, body_format, body_source, content_version)
SELECT id, body_format, body_source, content_version FROM posts
SQL);

        $db->execute(<<<'SQL'
ALTER TABLE posts
  DROP COLUMN content_version,
  DROP COLUMN body_source,
  DROP COLUMN body_format
SQL);
    },
);
