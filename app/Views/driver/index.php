<h1>Мои рейсы</h1>
<?php if (!$current): ?>
    <p class="muted">Активных рейсов нет.</p>
<?php endif ?>
<div class="grid">
    <?php foreach ($current as $a): ?>
        <article class="card trip">
            <header><strong><?= e(fmt_range($a['starts_at'], $a['ends_at'])) ?></strong> <?= trip_status_badge($a['status']) ?></header>
            <dl class="details">
                <dt>Заявка</dt><dd>№<?= (int) $a['request_id'] ?></dd>
                <dt>Маршрут</dt><dd><?= e($a['pickup_location']) ?> → <?= e($a['destination']) ?></dd>
                <dt>Автомобиль</dt><dd><?= e($a['brand'] . ' ' . $a['model'] . ', ' . $a['registration_number']) ?></dd>
                <dt>Заказчик</dt><dd><?= e($a['requester_name']) ?><?php $phone = $a['contact_phone'] ?: $a['requester_phone']; ?><?= $phone ? ', ' . e($phone) : '' ?></dd>
                <?php if ($a['passenger_count']): ?><dt>Пассажиров</dt><dd><?= (int) $a['passenger_count'] ?></dd><?php endif ?>
                <?php if ($a['comment']): ?><dt>Комментарий</dt><dd><?= nl2br(e($a['comment'])) ?></dd><?php endif ?>
            </dl>
            <div class="actions">
                <?php if ($a['status'] === 'assigned'): ?>
                    <form method="post" action="/driver/assignments/<?= (int) $a['id'] ?>/ack" class="inline"><?= csrf_field($csrf) ?><button class="btn primary">Подтвердить получение</button></form>
                <?php endif ?>
                <?php if (in_array($a['status'], ['assigned', 'acknowledged'], true)): ?>
                    <form method="post" action="/driver/assignments/<?= (int) $a['id'] ?>/start" class="inline"><?= csrf_field($csrf) ?><button class="btn">Начать рейс</button></form>
                <?php endif ?>
                <?php if ($a['status'] === 'in_progress'): ?>
                    <form method="post" action="/driver/assignments/<?= (int) $a['id'] ?>/finish" class="inline" data-confirm="Завершить рейс?"><?= csrf_field($csrf) ?><button class="btn primary">Завершить рейс</button></form>
                <?php endif ?>
            </div>
        </article>
    <?php endforeach ?>
</div>
<?php if ($history): ?>
    <h2>История</h2>
    <table class="table">
        <thead><tr><th>Когда</th><th>Маршрут</th><th>Автомобиль</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($history as $a): ?>
            <tr>
                <td><?= e(fmt_range($a['starts_at'], $a['ends_at'])) ?></td>
                <td><?= e($a['pickup_location']) ?> → <?= e($a['destination']) ?></td>
                <td><?= e($a['registration_number']) ?></td>
                <td><?= trip_status_badge($a['status']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
