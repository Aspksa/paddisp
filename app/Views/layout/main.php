<?php /** @var string $content */ ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/"><?= e($appName) ?></a>
    <?php if ($user): ?>
        <nav class="nav">
            <?php if ($auth->can('view_own_requests')): ?><a href="/requests">Мои заявки</a><?php endif ?>
            <?php if ($auth->can('create_own_request')): ?><a href="/requests/new">Новая заявка</a><?php endif ?>
            <?php if ($auth->can('view_all_requests')): ?><a href="/dispatch">Диспетчерская</a><?php endif ?>
            <?php if ($auth->can('view_own_assignments')): ?><a href="/driver">Мои рейсы</a><?php endif ?>
            <?php if ($auth->can('view_fleet')): ?><a href="/fleet">Автопарк</a><?php endif ?>
            <?php if ($auth->can('view_audit_log')): ?><a href="/dispatch/audit">Журнал</a><?php endif ?>
            <?php if ($auth->can('manage_users')): ?><a href="/admin">Администрирование</a><?php endif ?>
            <a href="/notifications" class="bell">Уведомления<?php if ($unread > 0): ?> <span class="counter"><?= (int) $unread ?></span><?php endif ?></a>
        </nav>
        <div class="userbox">
            <span><?= e($user['full_name']) ?></span>
            <a href="/password">Пароль</a>
            <form method="post" action="/logout" class="inline"><?= csrf_field($csrf) ?><button class="link">Выйти</button></form>
        </div>
    <?php endif ?>
</header>
<main class="container">
    <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach ?>
    <?= $content ?>
</main>
</body>
</html>
