<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'host' => Env::get('DB_HOST', 'localhost'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_NAME', ''),
    'user' => Env::get('DB_USER', ''),
    'pass' => Env::get('DB_PASS', ''),
];
