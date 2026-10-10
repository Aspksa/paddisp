<div class="page-head">
    <h1>Уведомления</h1>
    <?php if ($unread > 0): ?>
        <form method="post" action="/notifications/read-all" class="inline"><?= csrf_field($csrf) ?><button class="btn">Отметить все прочитанными</button></form>
    <?php endif ?>
</div>
<?php if (!$items): ?>
    <p class="muted">Уведомлений нет.</p>
<?php endif ?>
<ul class="notifications">
    <?php foreach ($items as $n): ?>
        <li class="<?= $n['read_at'] ? 'read' : 'unread' ?>">
            <div>
                <strong><?= e($n['title']) ?></strong>
                <?php if ($n['body']): ?><div><?= e($n['body']) ?></div><?php endif ?>
                <div class="muted small"><?= e(fmt_dt($n['created_at'])) ?></div>
            </div>
            <form method="post" action="/notifications/<?= (int) $n['id'] ?>/read" class="inline">
                <?= csrf_field($csrf) ?>
                <button class="btn"><?= $n['link'] ? 'Открыть' : 'Прочитано' ?></button>
            </form>
        </li>
    <?php endforeach ?>
</ul>
