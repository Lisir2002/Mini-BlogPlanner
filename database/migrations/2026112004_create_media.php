<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112004,
    name: 'create_media',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  disk_name VARCHAR(190) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  uploader_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_media_disk_name (disk_name),
  KEY idx_media_created (created_at),
  CONSTRAINT fk_media_uploader FOREIGN KEY (uploader_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS media');
    },
);
