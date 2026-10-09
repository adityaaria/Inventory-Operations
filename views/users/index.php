<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users - Inventory & Order Management</title>
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
    <?php $workspaceTitle = 'Users'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Users</h1>
                <p class="page-subtitle">Manage internal access for operational roles.</p>
            </div>
            <nav class="toolbar" aria-label="Page actions">

                <a class="button-primary" href="/users/create">Create User</a>
                <button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
            <dialog open class="modal-panel" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                <header class="modal-header">
                    <h2 id="import-dialog-title">Import CSV</h2>
                    <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                </header>
                <div class="modal-body">
                    <form method="post" action="/users/import" enctype="multipart/form-data" class="form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,password,role"></textarea></label>
                        <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                        <div class="modal-footer">
                            <button type="button" class="button button-quiet" data-dialog-close>Cancel</button>
                                <button type="submit">Import CSV</button>
                        </div>
                    </form>
                </div>
            </dialog>
        </div>
        <section class="table-scroll" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>">
<table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($user->email(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($user->role(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status-badge <?= $user->isActive() ? 'status-active status-success' : 'status-cancelled status-danger' ?>"><?= $user->isActive() ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <a class="action-link" href="/users/edit?id=<?= $user->id() ?>">Edit</a>
                            <form method="post" action="<?= $user->isActive() ? '/users/deactivate' : '/users/activate' ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= $user->id() ?>">
                                <button type="submit"><?= $user->isActive() ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</section>
        <?php require dirname(__DIR__) . "/partials/pagination.php"; ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
