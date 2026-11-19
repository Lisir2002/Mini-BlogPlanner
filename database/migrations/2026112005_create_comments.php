<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112005,
    name: 'create_comments',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  author_name VARCHAR(40) NOT NULL,
  author_email VARCHAR(190) NULL,
  body TEXT NOT NULL,
  status ENUM('pending','approved','spam') NOT NULL DEFAULT 'pending',
  ip_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  approved_at DATETIME NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comments_post (post_id, status, created_at),
  KEY idx_comments_parent (parent_id, status),
  KEY idx_comments_ip (ip_hash, created_at),
  CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES comments (id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_approver FOREIGN KEY (approved_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS comments');
    },
);
