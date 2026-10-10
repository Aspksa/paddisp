<div class="page-head">
    <h1>Заявка №<?= (int) $r['id'] ?> <?= request_status_badge($r['status']) ?></h1>
    <?php if ($auth->can('view_all_requests')): ?><a class="btn" href="/dispatch/requests/<?= (int) $r['id'] ?>">Открыть в диспетчерской</a><?php endif ?>
</div>
<div class="cols">
    <section class="card">
        <dl class="details">
            <dt>Когда</dt><dd><?= e(fmt_range($r['starts_at'], $r['ends_at'])) ?></dd>
            <dt>Заказчик</dt><dd><?= e($r['requester_name']) ?></dd>
            <dt>Подразделение</dt><dd><?= e($r['department_name']) ?></dd>
            <dt>Место подачи</dt><dd><?= e($r['pickup_location']) ?></dd>
            <dt>Пункт назначения</dt><dd><?= e($r['destination']) ?></dd>
            <dt>Цель</dt><dd><?= nl2br(e($r['purpose'])) ?></dd>
            <?php if ($r['passenger_count']): ?><dt>Пассажиров</dt><dd><?= (int) $r['passenger_count'] ?></dd><?php endif ?>
            <?php if ($r['contact_phone']): ?><dt>Телефон</dt><dd><?= e($r['contact_phone']) ?></dd><?php endif ?>
            <?php if ($r['preferred_vehicle_id']): ?><dt>Желаемый автомобиль</dt><dd><?= e($r['preferred_vehicle_brand'] . ' ' . $r['preferred_vehicle_model'] . ', ' . $r['preferred_vehicle_number']) ?></dd><?php endif ?>
            <?php if ($r['comment']): ?><dt>Комментарий</dt><dd><?= nl2br(e($r['comment'])) ?></dd><?php endif ?>
            <?php if ($r['decision_reason']): ?><dt>Причина решения</dt><dd><?= e($r['decision_reason']) ?></dd><?php endif ?>
        </dl>
        <?php if ($isOwner): ?>
            <div class="actions">
                <?php if ($r['status'] === 'draft'): ?>
                    <a class="btn" href="/requests/<?= (int) $r['id'] ?>/edit">Редактировать</a>
                    <form method="post" action="/requests/<?= (int) $r['id'] ?>/submit" class="inline"><?= csrf_field($csrf) ?><button class="btn primary">Подать</button></form>
                <?php endif ?>
                <?php if (in_array($r['status'], ['draft', 'submitted', 'under_review'], true)): ?>
                    <form method="post" action="/requests/<?= (int) $r['id'] ?>/cancel" class="inline" data-confirm="Отменить заявку?"><?= csrf_field($csrf) ?><button class="btn danger">Отменить заявку</button></form>
                <?php elseif ($r['status'] === 'approved'): ?>
                    <p class="muted small">Чтобы отменить или перенести подтверждённую поездку, обратитесь к диспетчеру.</p>
                <?php endif ?>
            </div>
        <?php endif ?>
    </section>
    <section class="card">
        <h2>Назначение</h2>
        <?php if ($assignment): ?>
            <dl class="details">
                <dt>Автомобиль</dt><dd><?= e($assignment['brand'] . ' ' . $assignment['model'] . ', ' . $assignment['registration_number']) ?></dd>
                <dt>Водитель</dt><dd><?= e($assignment['driver_name']) ?><?= $assignment['driver_phone'] ? ', ' . e($assignment['driver_phone']) : '' ?></dd>
                <dt>Статус рейса</dt><dd><?= trip_status_badge($assignment['status']) ?></dd>
            </dl>
        <?php else: ?>
            <p class="muted">Автомобиль и водитель ещё не назначены.</p>
        <?php endif ?>
        <?php if ($history): ?>
            <h2>История</h2>
            <?= $view->renderPartial('dispatch/_history', ['history' => $history]) ?>
        <?php endif ?>
    </section>
</div>
