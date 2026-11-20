<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112102,
    name: 'drop_series',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
ALTER TABLE posts
  DROP FOREIGN KEY fk_posts_series,
  DROP KEY idx_series,
  DROP COLUMN series_id
SQL);
        $db->execute('DROP TABLE IF EXISTS series');
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS series (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_series_slug (slug)
) ENGINE=InnoDB
SQL);
        $db->execute(<<<'SQL'
ALTER TABLE posts
  ADD COLUMN series_id BIGINT UNSIGNED NULL AFTER reading_minutes,
  ADD KEY idx_series (series_id, published_at),
  ADD CONSTRAINT fk_posts_series FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE SET NULL
SQL);
    },
);
