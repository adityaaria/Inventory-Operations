<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Trail - Inventory & Order Management</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script defer src="/assets/js/http.js"></script>
    <script defer src="/assets/js/ui-helpers.js"></script>
    <script defer src="/assets/js/form-validation.js"></script>
    <script defer src="/assets/js/navigation.js"></script>
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/dialog.js"></script>
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
<?php $workspaceTitle='Audit Trail'; require dirname(__DIR__).'/partials/workspace-start.php'; $escape=static fn($value): string => htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); ?>
<main class="page" id="main-content">
<header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Audit Trail</h1><p class="page-subtitle">Read-only activity history. Dates use the database timezone.</p></div></header>
<?php if ($error!==''): ?><p class="alert alert-danger" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<form method="get" action="/audit-trail" class="filters">
<label>Action <input name="action" maxlength="80" value="<?= $escape($filters['action']) ?>" placeholder="Exact action, e.g. auth.login_failed"></label>
<label>Actor ID <input name="actor_id" inputmode="numeric" value="<?= $escape($filters['actor_id']) ?>"></label>
<label>Status <select name="status"><option value="">All statuses</option><?php foreach (['success','failure','blocked'] as $statusValue): ?><option value="<?= $statusValue ?>" <?= $filters['status']===$statusValue?'selected':'' ?>><?= ucfirst($statusValue) ?></option><?php endforeach; ?></select></label>
<label>From <input type="date" name="from" value="<?= $escape($filters['from']) ?>"></label>
<label>To <input type="date" name="to" value="<?= $escape($filters['to']) ?>"></label>
<button type="submit">Apply Filters</button><a class="button" href="/audit-trail">Reset</a>
</form>
<?php if ($result!==null): ?>
<section class="table-scroll" aria-label="Audit trail table"><table class="data-table"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Entity</th><th>Status</th></tr></thead><tbody>
<?php foreach ($result->items() as $row): ?><tr><td><?= $escape($row['created_at']) ?></td><td><?= $escape($row['actor_email'] ?? 'System / unavailable') ?><?php if ($row['actor_id']!==null): ?> (#<?= $escape($row['actor_id']) ?>)<?php endif; ?></td><td><?= $escape($row['action']) ?></td><td><?= $escape($row['entity_type']) ?> <?= $escape($row['entity_id'] ?? '') ?></td><td><span class="status-badge <?= $row['status']==='success'?'status-success':'status-danger' ?>"><?= $escape(ucfirst($row['status'])) ?></span></td></tr><?php endforeach; ?>
<?php if ($result->total()===0): ?><tr><td colspan="5">No audit records match these filters.</td></tr><?php endif; ?>
</tbody></table></section>
<?php require dirname(__DIR__).'/partials/pagination.php'; endif; ?>
</main><?php require dirname(__DIR__).'/partials/workspace-end.php'; ?>
</body></html>
