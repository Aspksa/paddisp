<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;

// Встроенный сервер PHP (php -S) для разработки: статические файлы отдаются как есть.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '43200');
session_save_path(BASE_PATH . '/storage/sessions');
session_name('PADSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$response = (new App($config, Database::connect($config->get('database'))))
    ->handle(Request::fromGlobals(new Session($_SESSION)));
if ($https) {
    $response->headers['Strict-Transport-Security'] = 'max-age=31536000';
}
$response->send();
