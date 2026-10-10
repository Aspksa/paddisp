<?php

declare(strict_types=1);

use App\Core\Clock;
use App\Core\Config;
use App\Core\Env;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

Env::load(BASE_PATH . '/.env');

$config = Config::load(BASE_PATH . '/config');
date_default_timezone_set($config->get('app.timezone'));
Clock::setTimezone(new DateTimeZone($config->get('app.timezone')));

if ($config->get('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

return $config;
