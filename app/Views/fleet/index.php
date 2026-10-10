<?php use App\Services\FleetService; ?>
<div class="page-head">
    <h1>Автопарк</h1>
    <form method="get" action="/fleet" class="inline-form">
        <label>Занятость на дату <input type="date" name="date" value="<?= e($date) ?>"></label>
        <button class="btn">Показать</button>
    </form>
</div>
<p class="muted">Состояние «сейчас» и подтверждённые поездки на <?= e((new DateTimeImmutable($date))->format('d.m.Y')) ?>. Заявка не резервирует машину, пока её не подтвердит диспетчер.</p>
<div class="grid">
    <?php foreach ($vehicles as $v): ?>
        <article class="card vehicle state-<?= e($v['state']) ?>">
            <header>
                <strong><?= e($v['brand'] . ' ' . $v['model']) ?></strong>
                <span class="state"><?= e(FleetService::STATE_LABELS[$v['state']]) ?></span>
            </header>
            <div class="plate"><?= e($v['registration_number']) ?></div>
            <div class="muted small">Мест: <?= (int) $v['seat_count'] ?></div>
            <?php if ($v['maintenance']): ?>
                <ul class="slots">
                    <?php foreach ($v['maintenance'] as $m): ?>
                        <li class="slot repair">Ремонт: <?= e(fmt_range($m['starts_at'], $m['ends_at'])) ?><?= $m['reason'] ? ' — ' . e($m['reason']) : '' ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
            <?php if ($v['bookings']): ?>
                <ul class="slots">
                    <?php foreach ($v['bookings'] as $b): ?>
                        <li class="slot busy"><?= e(fmt_range($b['starts_at'], $b['ends_at'])) ?> · <?= e($b['department_name']) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php elseif (!$v['maintenance']): ?>
                <p class="muted small">На этот день поездок нет.</p>
            <?php endif ?>
            <?php if ($canManage): ?><a class="small" href="/dispatch/vehicles/<?= (int) $v['id'] ?>">Статус и ремонты</a><?php endif ?>
        </article>
    <?php endforeach ?>
    <?php if (!$vehicles): ?><p class="muted">Автомобили ещё не добавлены.</p><?php endif ?>
</div>
