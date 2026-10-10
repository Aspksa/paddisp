<?php
$labels = [
    'create_draft' => 'Создан черновик', 'update_draft' => 'Черновик изменён', 'submit' => 'Заявка подана',
    'take_for_review' => 'Взята в работу', 'approve' => 'Подтверждена', 'reject' => 'Отклонена',
    'reassign' => 'Назначение изменено', 'cancel' => 'Отменена', 'complete' => 'Выполнена',
    'acknowledge' => 'Водитель подтвердил получение', 'start_trip' => 'Рейс начат', 'finish_trip' => 'Рейс завершён',
    'set_status' => 'Изменён статус', 'add_maintenance' => 'Добавлен ремонт', 'delete_maintenance' => 'Удалён ремонт',
    'create' => 'Создано', 'reset_password' => 'Сброшен пароль', 'activate' => 'Разблокирован', 'deactivate' => 'Заблокирован',
];
?>
<ul class="history">
    <?php foreach ($history as $h): ?>
        <li>
            <span class="muted small"><?= e(fmt_dt($h['created_at'])) ?></span>
            <?= e($labels[$h['action']] ?? $h['action']) ?>
            <span class="muted small">— <?= e($h['actor_name'] ?? 'система') ?></span>
        </li>
    <?php endforeach ?>
</ul>
