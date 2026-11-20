<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112104,
    name: 'add_post_drafts',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS post_drafts (
  post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  excerpt VARCHAR(500) NOT NULL DEFAULT '',
  tags_json TEXT NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  body_format VARCHAR(12) NOT NULL DEFAULT 'markdown',
  body_source MEDIUMTEXT NULL,
  body_html MEDIUMTEXT NOT NULL,
  base_version BIGINT UNSIGNED NOT NULL,
  draft_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_post_drafts_updated (updated_at),
  CONSTRAINT fk_post_drafts_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS post_drafts');
    },
);
