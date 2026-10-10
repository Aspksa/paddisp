<section class="card narrow">
    <h1>Вход</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="/login" class="form">
        <?= csrf_field($csrf) ?>
        <label>Логин<input name="login" value="<?= e($login) ?>" autocomplete="username" required autofocus></label>
        <label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="btn primary">Войти</button>
    </form>
    <p class="muted small">Учётные записи выдаёт администратор. Забыли пароль — обратитесь к администратору.</p>
</section>
