<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112001,
    name: 'create_audit_logs',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(80) NOT NULL,
  actor VARCHAR(120) NOT NULL,
  plan_id VARCHAR(64) NULL,
  backup_id VARCHAR(64) NULL,
  payload JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event_created (event, created_at),
  KEY idx_plan (plan_id)
) ENGINE=InnoDB
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS audit_logs');
    },
);
