<?php use App\Services\FleetService; ?>
<div class="page-head">
    <h1><?= e($v['brand'] . ' ' . $v['model'] . ', ' . $v['registration_number']) ?></h1>
    <a href="/fleet">← Автопарк</a>
</div>
<div class="cols">
    <section class="card">
        <h2>Статус</h2>
        <form method="post" action="/dispatch/vehicles/<?= (int) $v['id'] ?>/status" class="form">
            <?= csrf_field($csrf) ?>
            <select name="status">
                <?php foreach (FleetService::STATUS_LABELS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $v['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach ?>
            </select>
            <p class="muted small">«В ремонте» без даты окончания блокирует любые новые назначения. Для планового ремонта лучше добавить период ниже.</p>
            <button class="btn">Сохранить статус</button>
        </form>
        <h2>История</h2>
        <?= $view->renderPartial('dispatch/_history', ['history' => $history]) ?>
    </section>
    <section class="card">
        <h2>Периоды ремонта</h2>
        <?php if ($maintenance): ?>
            <ul class="slots">
                <?php foreach ($maintenance as $m): ?>
                    <li class="slot repair">
                        <?= e(fmt_range($m['starts_at'], $m['ends_at'])) ?><?= $m['reason'] ? ' — ' . e($m['reason']) : '' ?>
                        <form method="post" action="/dispatch/vehicles/<?= (int) $v['id'] ?>/maintenance/<?= (int) $m['id'] ?>/delete" class="inline" data-confirm="Удалить период ремонта?"><?= csrf_field($csrf) ?><button class="link">удалить</button></form>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php else: ?>
            <p class="muted">Запланированных ремонтов нет.</p>
        <?php endif ?>
        <form method="post" action="/dispatch/vehicles/<?= (int) $v['id'] ?>/maintenance" class="form">
            <?= csrf_field($csrf) ?>
            <div class="row">
                <label>С<input type="datetime-local" name="starts_at" required></label>
                <label>По<input type="datetime-local" name="ends_at" required></label>
            </div>
            <label>Причина<input name="reason" maxlength="255"></label>
            <button class="btn">Добавить ремонт</button>
        </form>
    </section>
</div>
