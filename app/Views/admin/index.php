<?php use App\Services\FleetService; ?>
<h1>Администрирование</h1>
<div class="cols">
    <section class="card">
        <h2>Новый пользователь</h2>
        <form method="post" action="/admin/users" class="form">
            <?= csrf_field($csrf) ?>
            <label>Логин<input name="login" pattern="[a-z0-9._\-]{3,64}" required></label>
            <label>ФИО<input name="full_name" maxlength="190" required></label>
            <label>Роль
                <select name="role" required>
                    <?php foreach ($roles as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach ?>
                </select>
            </label>
            <label>Подразделение
                <select name="department_id">
                    <option value="">—</option>
                    <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach ?>
                </select>
            </label>
            <label>Телефон<input name="phone" maxlength="32"></label>
            <p class="muted small">Будет создан временный пароль; при первом входе пользователь задаст свой.</p>
            <button class="btn primary">Создать</button>
        </form>
    </section>
    <section class="card">
        <h2>Подразделения</h2>
        <ul><?php foreach ($departments as $d): ?><li><?= e($d['name']) ?></li><?php endforeach ?></ul>
        <form method="post" action="/admin/departments" class="inline-form">
            <?= csrf_field($csrf) ?>
            <input name="name" placeholder="Название" maxlength="190" required>
            <button class="btn">Добавить</button>
        </form>
        <h2>Автомобили</h2>
        <ul>
            <?php foreach ($vehicles as $v): ?>
                <li><?= e($v['brand'] . ' ' . $v['model'] . ', ' . $v['registration_number']) ?> — <?= e(FleetService::STATUS_LABELS[$v['status']]) ?></li>
            <?php endforeach ?>
        </ul>
        <form method="post" action="/admin/vehicles" class="form">
            <?= csrf_field($csrf) ?>
            <div class="row">
                <label>Марка<input name="brand" maxlength="64" required></label>
                <label>Модель<input name="model" maxlength="64" required></label>
            </div>
            <div class="row">
                <label>Госномер<input name="registration_number" maxlength="20" required></label>
                <label>Мест<input type="number" name="seat_count" min="1" max="8" value="4" required></label>
            </div>
            <button class="btn">Добавить автомобиль</button>
        </form>
    </section>
</div>
<section class="card">
    <h2>Пользователи</h2>
    <table class="table">
        <thead><tr><th>ФИО</th><th>Логин</th><th>Роль</th><th>Подразделение</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?= (int) $u['active'] ? '' : 'muted' ?>">
                <td><?= e($u['full_name']) ?></td>
                <td><?= e($u['login']) ?></td>
                <td><?= e($roles[$u['role']] ?? $u['role']) ?></td>
                <td><?= e($u['department_name'] ?? '—') ?></td>
                <td><?= (int) $u['active'] ? 'активен' : 'заблокирован' ?><?= (int) $u['must_change_password'] ? ', временный пароль' : '' ?></td>
                <td class="nowrap">
                    <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/reset-password" class="inline" data-confirm="Сбросить пароль?"><?= csrf_field($csrf) ?><button class="link">сбросить пароль</button></form>
                    <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                        <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/toggle" class="inline"><?= csrf_field($csrf) ?><button class="link"><?= (int) $u['active'] ? 'заблокировать' : 'разблокировать' ?></button></form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>
