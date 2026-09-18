<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Warehouses - Inventory & Order Management</title>
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
</head>
<body>
    <main class="page">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Warehouses</h1>
                <p class="page-subtitle">Review storage locations and availability.</p>
            </div>
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/warehouses/create">Create Warehouse</a><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <form method="post" action="/warehouses/import" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,location"></textarea></label>
                <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                <button type="submit">Import CSV</button>
            </form>
        <?php endif; ?>
        <table class="data-table">
            <thead><tr><th>Name</th><th>Location</th><th>Status</th><?php if ($canWrite): ?><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($warehouses as $warehouse): ?>
                    <tr>
                        <td><?= htmlspecialchars($warehouse->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($warehouse->location(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status-badge <?= $warehouse->isActive() ? 'status-active' : 'status-cancelled' ?>"><?= $warehouse->isActive() ? 'Active' : 'Inactive' ?></span></td>
                        <?php if ($canWrite): ?>
                            <td>
                                <a class="action-link" href="/warehouses/edit?id=<?= $warehouse->id() ?>">Edit</a>
                                <form method="post" action="<?= $warehouse->isActive() ? '/warehouses/deactivate' : '/warehouses/activate' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= $warehouse->id() ?>">
                                    <button type="submit"><?= $warehouse->isActive() ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
