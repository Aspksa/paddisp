<section class="card narrow">
    <h1>Смена пароля</h1>
    <?php if ($forced): ?><div class="flash flash-warning">Вы вошли с временным паролем. Задайте собственный пароль, чтобы продолжить.</div><?php endif ?>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="/password" class="form">
        <?= csrf_field($csrf) ?>
        <label>Текущий пароль<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Новый пароль (не менее 10 символов)<input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
        <label>Повторите новый пароль<input type="password" name="new_password_confirm" autocomplete="new-password" minlength="10" required></label>
        <button class="btn primary">Сохранить</button>
    </form>
</section>
