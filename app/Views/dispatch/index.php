<div class="page-head">
    <h1>Диспетчерская</h1>
    <nav class="tabs">
        <a href="/dispatch?tab=queue" class="<?= $tab === 'queue' ? 'active' : '' ?>">Ожидают решения</a>
        <a href="/dispatch?tab=approved" class="<?= $tab === 'approved' ? 'active' : '' ?>">Подтверждённые</a>
        <a href="/dispatch?tab=closed" class="<?= $tab === 'closed' ? 'active' : '' ?>">Закрытые</a>
    </nav>
</div>
<?php if (!$requests): ?>
    <p class="muted">Заявок нет.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>№</th><th>Когда</th><th>Заказчик</th><th>Маршрут</th><th>Назначение</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><a href="/dispatch/requests/<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
                <td><?= e(fmt_range($r['starts_at'], $r['ends_at'])) ?></td>
                <td><?= e($r['requester_name']) ?><div class="muted small"><?= e($r['department_name']) ?></div></td>
                <td><?= e($r['pickup_location']) ?> → <?= e($r['destination']) ?></td>
                <td><?php if ($r['registration_number']): ?><?= e($r['registration_number']) ?>, <?= e($r['driver_name']) ?><div><?= trip_status_badge($r['assignment_status']) ?></div><?php else: ?>—<?php endif ?></td>
                <td><?= request_status_badge($r['status']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
