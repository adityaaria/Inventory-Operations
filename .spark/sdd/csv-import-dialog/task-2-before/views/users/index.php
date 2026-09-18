<!doctype html>
<html lang="en">
<head>
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
                <h1>Users</h1>
                <p class="page-subtitle">Manage internal access for operational roles.</p>
            </div>
            <nav class="toolbar">
                <a href="/">Home</a>
                <a class="button-primary" href="/users/create">Create User</a>
                <form method="post" action="/logout">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Logout</button>
                </form>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/users/import" enctype="multipart/form-data" class="form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,password,role"></textarea></label>
            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
            <button type="submit">Import CSV</button>
        </form>
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
                        <td><span class="status-badge <?= $user->isActive() ? 'status-active' : 'status-cancelled' ?>"><?= $user->isActive() ? 'Active' : 'Inactive' ?></span></td>
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
    </main>
</body>
</html>
