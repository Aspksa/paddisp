<div class="page-head">
    <h1>Мои заявки</h1>
    <?php if ($auth->can('create_own_request')): ?><a class="btn primary" href="/requests/new">Новая заявка</a><?php endif ?>
</div>
<?php if (!$requests): ?>
    <p class="muted">Заявок пока нет.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>№</th><th>Когда</th><th>Маршрут</th><th>Подразделение</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><a href="/requests/<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
                <td><?= e(fmt_range($r['starts_at'], $r['ends_at'])) ?></td>
                <td><?= e($r['pickup_location']) ?> → <?= e($r['destination']) ?></td>
                <td><?= e($r['department_name']) ?></td>
                <td><?= request_status_badge($r['status']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
