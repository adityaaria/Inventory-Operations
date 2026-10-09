<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Warehouse - Inventory & Order Management</title>
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
    <?php $formState = new \App\Support\FormState($old ?? []); ?>
    <?php $workspaceTitle = 'Edit Warehouse'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Edit Warehouse</h1><p class="page-subtitle">Update the warehouse details.</p></div><nav class="toolbar" aria-label="Page actions"><a href="/warehouses">Warehouses</a></nav></header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($warehouse !== null): ?>
            <form method="post" action="/warehouses/update" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $warehouse->id() ?>">
                <label class="field"><span class="field-label">Name</span><input name="name" required value="<?= htmlspecialchars($formState->value('name', $warehouse->name()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                <label class="field"><span class="field-label">Location</span><input name="location" value="<?= htmlspecialchars($formState->value('location', $warehouse->location()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                <div class="form-actions"><button type="submit">Update</button><button type="button" class="button button-quiet" data-cancel-href="/warehouses">Cancel</button></div>
            </form>
        <?php endif; ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
