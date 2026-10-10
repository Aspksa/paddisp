<?php

declare(strict_types=1);

// Демо-данные для разработки: php bin/seed-dev.php
// Пароль всех демо-пользователей: demo-password

use App\Core\Database;
use Database\Seeds\DevSeeder;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require dirname(__DIR__) . '/app/bootstrap.php';
if (!in_array($config->get('app.env'), ['development', 'testing'], true)) {
    fwrite(STDERR, "Демо-данные загружаются только при APP_ENV=development.\n");
    exit(1);
}
require BASE_PATH . '/database/seeds/DevSeeder.php';
$db = Database::connect($config->get('database'));
if ((int) $db->value('SELECT COUNT(*) FROM users') > 0) {
    fwrite(STDERR, "В базе уже есть пользователи — демо-данные не загружены.\n");
    exit(1);
}
(new DevSeeder($db))->run();
echo "Демо-данные загружены. Логины: admin, dispatcher, employee, employee2, driver, driver2, driver3. Пароль: " . DevSeeder::PASSWORD . PHP_EOL;
