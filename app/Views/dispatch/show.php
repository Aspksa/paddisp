<div class="page-head">
    <h1>Заявка №<?= (int) $r['id'] ?> <?= request_status_badge($r['status']) ?></h1>
    <a href="/dispatch">← К списку</a>
</div>
<div class="cols">
    <section class="card">
        <dl class="details">
            <dt>Когда</dt><dd><strong><?= e(fmt_range($r['starts_at'], $r['ends_at'])) ?></strong></dd>
            <dt>Заказчик</dt><dd><?= e($r['requester_name']) ?><?= $r['requester_phone'] ? ', ' . e($r['requester_phone']) : '' ?></dd>
            <dt>Подразделение</dt><dd><?= e($r['department_name']) ?></dd>
            <dt>Место подачи</dt><dd><?= e($r['pickup_location']) ?></dd>
            <dt>Пункт назначения</dt><dd><?= e($r['destination']) ?></dd>
            <dt>Цель</dt><dd><?= nl2br(e($r['purpose'])) ?></dd>
            <?php if ($r['passenger_count']): ?><dt>Пассажиров</dt><dd><?= (int) $r['passenger_count'] ?></dd><?php endif ?>
            <?php if ($r['contact_phone']): ?><dt>Контактный телефон</dt><dd><?= e($r['contact_phone']) ?></dd><?php endif ?>
            <?php if ($r['preferred_vehicle_id']): ?><dt>Пожелание по машине</dt><dd><?= e($r['preferred_vehicle_brand'] . ' ' . $r['preferred_vehicle_model'] . ', ' . $r['preferred_vehicle_number']) ?></dd><?php endif ?>
            <?php if ($r['comment']): ?><dt>Комментарий</dt><dd><?= nl2br(e($r['comment'])) ?></dd><?php endif ?>
            <?php if ($r['decision_reason']): ?><dt>Причина решения</dt><dd><?= e($r['decision_reason']) ?></dd><?php endif ?>
        </dl>
        <h2>История</h2>
        <?= $view->renderPartial('dispatch/_history', ['history' => $history]) ?>
    </section>

    <section class="card">
        <?php if (in_array($r['status'], ['submitted', 'under_review'], true)): ?>
            <?php if ($r['status'] === 'submitted'): ?>
                <form method="post" action="/dispatch/requests/<?= (int) $r['id'] ?>/review" class="inline"><?= csrf_field($csrf) ?><button class="btn">Взять в работу</button></form>
            <?php endif ?>
            <h2>Подтвердить</h2>
            <form method="post" action="/dispatch/requests/<?= (int) $r['id'] ?>/approve" class="form">
                <?= csrf_field($csrf) ?>
                <?= $view->renderPartial('dispatch/_options', ['vehicles' => $vehicles, 'drivers' => $drivers, 'selectedVehicle' => $r['preferred_vehicle_id']]) ?>
                <p class="muted small">Недоступные на это время машины и водители отмечены и не выбираются. Занятость повторно проверяется при сохранении.</p>
                <button class="btn primary">Подтвердить и назначить</button>
            </form>
            <h2>Отклонить</h2>
            <form method="post" action="/dispatch/requests/<?= (int) $r['id'] ?>/reject" class="form">
                <?= csrf_field($csrf) ?>
                <label>Причина*<textarea name="decision_reason" rows="2" maxlength="1000" required></textarea></label>
                <button class="btn danger">Отклонить</button>
            </form>
        <?php elseif ($r['status'] === 'approved' && $assignment): ?>
            <h2>Назначение</h2>
            <dl class="details">
                <dt>Автомобиль</dt><dd><?= e($assignment['brand'] . ' ' . $assignment['model'] . ', ' . $assignment['registration_number']) ?></dd>
                <dt>Водитель</dt><dd><?= e($assignment['driver_name']) ?></dd>
                <dt>Рейс</dt><dd><?= trip_status_badge($assignment['status']) ?></dd>
            </dl>
            <?php if ($assignment['status'] !== 'in_progress'): ?>
                <h2>Изменить или перенести</h2>
                <form method="post" action="/dispatch/requests/<?= (int) $r['id'] ?>/reassign" class="form">
                    <?= csrf_field($csrf) ?>
                    <div class="row">
                        <label>Начало<input type="datetime-local" name="starts_at" value="<?= e(str_replace(' ', 'T', substr($r['starts_at'], 0, 16))) ?>"></label>
                        <label>Окончание<input type="datetime-local" name="ends_at" value="<?= e(str_replace(' ', 'T', substr($r['ends_at'], 0, 16))) ?>"></label>
                    </div>
                    <?= $view->renderPartial('dispatch/_options', ['vehicles' => $vehicles, 'drivers' => $drivers, 'selectedVehicle' => $assignment['vehicle_id'], 'selectedDriver' => $assignment['driver_id']]) ?>
                    <p class="muted small">Пометки занятости рассчитаны для текущего времени поездки. При переносе занятость проверяется заново при сохранении.</p>
                    <button class="btn primary">Сохранить изменения</button>
                </form>
                <h2>Отменить поездку</h2>
                <form method="post" action="/dispatch/requests/<?= (int) $r['id'] ?>/cancel" class="form">
                    <?= csrf_field($csrf) ?>
                    <label>Причина*<textarea name="decision_reason" rows="2" maxlength="1000" required></textarea></label>
                    <button class="btn danger">Отменить поездку</button>
                </form>
            <?php endif ?>
        <?php else: ?>
            <p class="muted">Заявка закрыта, действий нет.</p>
        <?php endif ?>
    </section>
</div>
