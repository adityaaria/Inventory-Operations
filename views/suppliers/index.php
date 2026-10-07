<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suppliers - Inventory & Order Management</title>
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
    <?php $workspaceTitle = 'Suppliers'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Suppliers</h1>
                <p class="page-subtitle">Maintain purchasing partner records.</p>
            </div>
            <nav class="toolbar">

                <?php if ($canWrite): ?><a class="button-primary" href="/suppliers/create">Create Supplier</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/suppliers/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,phone,address"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <div class="modal-footer"><button type="button" class="button button-quiet" data-dialog-close>Cancel</button><button type="submit">Import CSV</button></div>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        <div class="table-scroll" role="region" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
<table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Status</th><?php if ($canWrite): ?><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?= htmlspecialchars($supplier->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($supplier->email(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($supplier->phone(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($supplier->address(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status-badge <?= $supplier->isActive() ? 'status-active status-success' : 'status-cancelled status-danger' ?>"><?= $supplier->isActive() ? 'Active' : 'Inactive' ?></span></td>
                        <?php if ($canWrite): ?>
                            <td>
                                <a class="action-link" href="/suppliers/edit?id=<?= $supplier->id() ?>">Edit</a>
                                <form method="post" action="<?= $supplier->isActive() ? '/suppliers/deactivate' : '/suppliers/activate' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= $supplier->id() ?>">
                                    <button type="submit"><?= $supplier->isActive() ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
        <?php require dirname(__DIR__) . "/partials/pagination.php"; ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
