<?php /** Выбор автомобиля и водителя с пометками о конфликтах. */ ?>
<label>Автомобиль
    <select name="vehicle_id" required>
        <option value="">— выберите —</option>
        <?php foreach ($vehicles as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= $v['problems'] ? 'disabled' : '' ?> <?= (int) $v['id'] === (int) ($selectedVehicle ?? 0) ? 'selected' : '' ?>>
                <?= e($v['brand'] . ' ' . $v['model'] . ', ' . $v['registration_number'] . ' (' . $v['seat_count'] . ' мест)') ?><?= $v['problems'] ? ' — ' . e(implode('; ', $v['problems'])) : '' ?>
            </option>
        <?php endforeach ?>
    </select>
</label>
<label>Водитель
    <select name="driver_id" required>
        <option value="">— выберите —</option>
        <?php foreach ($drivers as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= $d['problems'] ? 'disabled' : '' ?> <?= (int) $d['id'] === (int) ($selectedDriver ?? 0) ? 'selected' : '' ?>>
                <?= e($d['full_name']) ?><?= $d['problems'] ? ' — ' . e(implode('; ', $d['problems'])) : '' ?>
            </option>
        <?php endforeach ?>
    </select>
</label>
