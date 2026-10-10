<?php

declare(strict_types=1);

// Запуск тестов: php tests/run.php [фильтр]
// Нужна отдельная тестовая база MySQL/MariaDB — она полностью очищается!
// Параметры: TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS.

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Services\AuthService;
use Tests\AssertionFailed;
use Tests\TestCase;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$config->set('app.env', 'testing');
$config->set('app.debug', false);
ini_set('display_errors', '1');
ini_set('error_log', '');
$config->set('app.timezone', 'Asia/Vladivostok');
date_default_timezone_set('Asia/Vladivostok');
App\Core\Clock::setTimezone(new DateTimeZone('Asia/Vladivostok'));

$dbConfig = [
    'host' => Env::get('TEST_DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('TEST_DB_PORT', '3306'),
    'name' => Env::get('TEST_DB_NAME', 'paddisp_test'),
    'user' => Env::get('TEST_DB_USER', 'paddisp'),
    'pass' => Env::get('TEST_DB_PASS', 'paddisp'),
];
if (!str_contains((string) $dbConfig['name'], 'test')) {
    fwrite(STDERR, "Имя тестовой базы должно содержать «test» — она будет очищена.\n");
    exit(1);
}
$config->set('database', $dbConfig);

$db = Database::connect($dbConfig);
$migrator = new Migrator($db, BASE_PATH . '/database/migrations');
$migrator->dropAll();
$migrator->migrate();

require __DIR__ . '/TestCase.php';

TestCase::$db = $db;
TestCase::$config = $config;
TestCase::$passwordHash = AuthService::hash(TestCase::PASSWORD);

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    require $file;
}

$filter = $argv[1] ?? null;
$passed = 0;
$failed = [];
foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, TestCase::class)) {
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = (new ReflectionClass($class))->getShortName() . '::' . $method;
        if ($filter !== null && !str_contains($name, $filter)) {
            continue;
        }
        $test = new $class();
        try {
            $test->setUp();
            $test->$method();
            $passed++;
            echo '.';
        } catch (AssertionFailed $e) {
            $failed[] = "$name\n    " . $e->getMessage() . "\n    " . basename($e->getTrace()[0]['file'] ?? '') . ':' . ($e->getTrace()[0]['line'] ?? '');
            echo 'F';
        } catch (Throwable $e) {
            $failed[] = "$name\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine();
            echo 'E';
        }
    }
}
echo PHP_EOL . PHP_EOL;
foreach ($failed as $f) {
    echo "FAIL $f" . PHP_EOL;
}
echo sprintf('Пройдено: %d, ошибок: %d', $passed, count($failed)) . PHP_EOL;
exit($failed ? 1 : 0);
