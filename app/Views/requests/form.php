<?php
$v = static fn (string $k): string => e($values[$k] ?? '');
$err = static fn (string $k): string => isset($errors[$k]) ? '<span class="error">' . e($errors[$k]) . '</span>' : '';
$action = $id ? "/requests/$id" : '/requests';
?>
<h1><?= $id ? 'Черновик заявки №' . (int) $id : 'Новая заявка на автомобиль' ?></h1>
<?php if ($errors): ?><div class="flash flash-error">Проверьте заполнение формы.</div><?php endif ?>
<form method="post" action="<?= e($action) ?>" class="form card">
    <?= csrf_field($csrf) ?>
    <div class="row">
        <label>Дата поездки*<input type="date" name="requested_date" value="<?= $v('requested_date') ?>" required><?= $err('requested_date') ?></label>
        <label>Начало*<input type="time" name="start_time" value="<?= $v('start_time') ?>" required></label>
        <label>Окончание*<input type="time" name="end_time" value="<?= $v('end_time') ?>" required><?= $err('end_time') ?></label>
        <label>Дата окончания<input type="date" name="end_date" value="<?= $v('end_date') ?>"><span class="hint">если поездка заканчивается на следующий день</span></label>
    </div>
    <label>Подразделение*
        <select name="department_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (string) ($values['department_id'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach ?>
        </select><?= $err('department_id') ?>
    </label>
    <label>Место подачи*<input name="pickup_location" maxlength="255" value="<?= $v('pickup_location') ?>" required><?= $err('pickup_location') ?></label>
    <label>Пункт назначения*<input name="destination" maxlength="255" value="<?= $v('destination') ?>" required><?= $err('destination') ?></label>
    <label>Цель поездки*<textarea name="purpose" maxlength="1000" rows="2" required><?= $v('purpose') ?></textarea><?= $err('purpose') ?></label>
    <div class="row">
        <label>Пассажиров<input type="number" name="passenger_count" min="1" max="50" value="<?= $v('passenger_count') ?>"><?= $err('passenger_count') ?></label>
        <label>Контактный телефон<input type="tel" name="contact_phone" maxlength="32" value="<?= $v('contact_phone') ?>"><?= $err('contact_phone') ?></label>
        <label>Желаемый автомобиль
            <select name="preferred_vehicle_id">
                <option value="">— на усмотрение диспетчера —</option>
                <?php foreach ($vehicles as $veh): ?>
                    <option value="<?= (int) $veh['id'] ?>" <?= (string) ($values['preferred_vehicle_id'] ?? '') === (string) $veh['id'] ? 'selected' : '' ?>><?= e($veh['brand'] . ' ' . $veh['model'] . ', ' . $veh['registration_number']) ?></option>
                <?php endforeach ?>
            </select><?= $err('preferred_vehicle_id') ?>
        </label>
    </div>
    <label>Комментарий<textarea name="comment" maxlength="1000" rows="2"><?= $v('comment') ?></textarea><?= $err('comment') ?></label>
    <p class="muted small">Автомобиль закрепляется только после подтверждения диспетчером. Если желаемая машина занята, вы увидите предупреждение, но заявку подать можно.</p>
    <div class="actions">
        <button class="btn primary" name="action" value="submit">Подать заявку</button>
        <button class="btn" name="action" value="draft">Сохранить черновик</button>
    </div>
</form>
