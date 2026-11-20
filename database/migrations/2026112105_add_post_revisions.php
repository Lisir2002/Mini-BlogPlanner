<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112105,
    name: 'add_post_revisions',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS post_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  excerpt VARCHAR(500) NOT NULL DEFAULT '',
  tags_json TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  body_format VARCHAR(12) NOT NULL DEFAULT 'html',
  body_source MEDIUMTEXT NULL,
  body_html MEDIUMTEXT NOT NULL,
  reading_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  author_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_post_revisions_version (post_id, version),
  KEY idx_post_revisions_time (post_id, published_at),
  CONSTRAINT fk_post_revisions_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL);

        $db->execute(<<<'SQL'
INSERT INTO post_revisions
  (post_id, version, title, slug, excerpt, tags_json, status, body_format, body_source, body_html,
   reading_minutes, author_id, published_at, created_at)
SELECT p.id, p.content_version, p.title, p.slug, p.excerpt,
  COALESCE((
    SELECT JSON_ARRAYAGG(t.name)
    FROM post_tags pt
    JOIN tags t ON t.id = pt.tag_id
    WHERE pt.post_id = p.id
  ), JSON_ARRAY()),
  p.status, p.body_format, p.body_source, p.body, p.reading_minutes, p.author_id,
  p.published_at, p.created_at
FROM posts p
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS post_revisions');
    },
);
