<?php $title='Work Queue';$subtitle='Current tasks for your role, oldest documents first. Age is whole days since document creation, not an SLA.';$toolbar='';require dirname(__DIR__).'/inventory-operations/start.php'; ?>
<section class="metric-grid">
<?php foreach($types as $type=>$label): ?><article class="metric-card"><span><?= $escape($label) ?></span><strong><?= (int)($counts[$type]??0) ?></strong></article><?php endforeach; ?>
</section>
<form method="get" action="/work-queue" class="filters"><label>Search document <input name="q" maxlength="120" value="<?= $escape($filters['q']) ?>"></label><label>Task <select name="type"><option value="">All my tasks</option><?php foreach($types as $type=>$label): ?><option value="<?= $escape($type) ?>" <?= $filters['type']===$type?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label><button type="submit">Apply Filters</button><a class="button" href="/work-queue">Reset</a></form>
<section class="table-scroll" aria-label="Work queue"><table class="data-table"><thead><tr><th>Task</th><th>Document</th><th>Status</th><th>Created</th><th>Age (days)</th><th>Next step</th></tr></thead><tbody>
<?php foreach($result->items() as $row): ?><tr><td><?= $escape($types[$row['task_type']]) ?></td><td><?= $escape($row['label']) ?></td><td><span class="status-badge status-pending"><?= $escape($row['status']) ?></span></td><td><?= $escape($row['created_at']) ?></td><td><?= (int)$row['age_days'] ?></td><td><a class="button" href="<?= $escape($row['url']) ?>"><?= $escape($row['action']) ?></a></td></tr><?php endforeach; ?>
<?php if($result->total()===0): ?><tr><td colspan="6">No tasks match these filters.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath='/work-queue';$paginationLabel='Tasks';$paginationQuery=$filters;require dirname(__DIR__).'/partials/pagination.php';require dirname(__DIR__).'/inventory-operations/end.php'; ?>
