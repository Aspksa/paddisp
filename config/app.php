<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => 'Примавтодор — Автодиспетчер',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::get('APP_DEBUG', '0') === '1',
    'url' => Env::get('APP_URL', 'https://paddisp.ru'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Vladivostok'),
    'login' => [
        'max_failures' => (int) Env::get('LOGIN_MAX_FAILURES', '5'),
        'max_ip_failures' => (int) Env::get('LOGIN_MAX_IP_FAILURES', '20'),
        'window_minutes' => (int) Env::get('LOGIN_WINDOW_MINUTES', '15'),
    ],
];
