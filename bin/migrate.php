<?php

declare(strict_types=1);

// Применение миграций: php bin/migrate.php

use App\Core\Database;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = Database::connect($config->get('database'));
$applied = (new Migrator($db, BASE_PATH . '/database/migrations'))->migrate();
echo $applied ? 'Применены: ' . implode(', ', $applied) . PHP_EOL : 'Новых миграций нет.' . PHP_EOL;
