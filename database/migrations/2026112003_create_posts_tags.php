<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112003,
    name: 'create_posts_tags',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(190) NOT NULL,
  title VARCHAR(200) NOT NULL,
  excerpt VARCHAR(500) NOT NULL DEFAULT '',
  body MEDIUMTEXT NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  author_id BIGINT UNSIGNED NULL,
  reading_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_feed (status, published_at),
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB
SQL);

        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB
SQL);

        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS post_tags (
  post_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, tag_id),
  KEY idx_post_tags_tag (tag_id),
  CONSTRAINT fk_post_tags_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_post_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS post_tags');
        $db->execute('DROP TABLE IF EXISTS posts');
        $db->execute('DROP TABLE IF EXISTS tags');
    },
);
