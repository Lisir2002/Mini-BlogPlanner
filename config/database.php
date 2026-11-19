<?php

declare(strict_types=1);

use Core\Config\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_NAME', 'cream_journal'),
    'user' => Env::get('DB_USER', 'cream'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
