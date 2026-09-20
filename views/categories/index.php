<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Categories - Inventory & Order Management</title>
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
                <h1>Categories</h1>
                <p class="page-subtitle">Organize product catalog groups.</p>
            </div>
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/categories/create">Create Category</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/categories/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,description"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        <table class="data-table">
            <thead><tr><th>Name</th><th>Description</th><th>Status</th><?php if ($canWrite): ?><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?= htmlspecialchars($category->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($category->description(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status-badge <?= $category->isActive() ? 'status-active status-success' : 'status-cancelled status-danger' ?>"><?= $category->isActive() ? 'Active' : 'Inactive' ?></span></td>
                        <?php if ($canWrite): ?>
                            <td>
                                <a class="action-link" href="/categories/edit?id=<?= $category->id() ?>">Edit</a>
                                <form method="post" action="<?= $category->isActive() ? '/categories/deactivate' : '/categories/activate' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= $category->id() ?>">
                                    <button type="submit"><?= $category->isActive() ? 'Deactivate' : 'Activate' ?></button>
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
