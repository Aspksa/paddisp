<h1>Журнал изменений</h1>
<table class="table">
    <thead><tr><th>Время</th><th>Кто</th><th>Объект</th><th>Действие</th><th>Было</th><th>Стало</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $a): ?>
        <tr>
            <td class="nowrap"><?= e(fmt_dt($a['created_at'])) ?></td>
            <td><?= e($a['actor_name'] ?? 'система') ?></td>
            <td>
                <?php if ($a['entity_type'] === 'request'): ?>
                    <a href="/dispatch/requests/<?= (int) $a['entity_id'] ?>">заявка №<?= (int) $a['entity_id'] ?></a>
                <?php else: ?>
                    <?= e($a['entity_type']) ?> #<?= (int) $a['entity_id'] ?>
                <?php endif ?>
            </td>
            <td><?= e($a['action']) ?></td>
            <td><code class="small"><?= e($a['before_json'] ?? '') ?></code></td>
            <td><code class="small"><?= e($a['after_json'] ?? '') ?></code></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
