<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026100101,
    name: 'create_site_settings',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
SQL);
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('DROP TABLE IF EXISTS site_settings');
    },
);
