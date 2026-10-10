<?php

declare(strict_types=1);

// Создание первого администратора (или любого пользователя) из консоли:
//   php bin/create-user.php <login> "<ФИО>" [role=admin]
// Временный пароль выводится один раз; при первом входе его нужно сменить.

use App\Core\Database;
use App\Services\AdminService;
use App\Services\AuditLog;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require dirname(__DIR__) . '/app/bootstrap.php';
[$_, $login, $name, $role] = array_pad($argv, 4, null);
if ($login === null || $name === null) {
    fwrite(STDERR, "Использование: php bin/create-user.php <login> \"<ФИО>\" [employee|dispatcher|driver|admin]\n");
    exit(1);
}
$db = Database::connect($config->get('database'));
$admin = new AdminService($db, new AuditLog($db));
try {
    $result = $admin->createUser(null, ['login' => $login, 'full_name' => $name, 'role' => $role ?? 'admin']);
} catch (App\Core\ValidationException $e) {
    fwrite(STDERR, implode(PHP_EOL, $e->errors) . PHP_EOL);
    exit(1);
}
echo "Пользователь создан. Логин: {$login}. Временный пароль: {$result['password']}" . PHP_EOL;
