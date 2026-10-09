<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit User - Inventory & Order Management</title>
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
    <?php $workspaceTitle = 'Edit User'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Edit User</h1><p class="page-subtitle">Update internal access and role details.</p></div><nav class="toolbar" aria-label="Page actions"><a href="/users">Users</a></nav></header>
        <?php if ($error !== ''): ?>
            <p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($user === null): ?>
            <p>User not found.</p>
        <?php else: ?>
            <form method="post" action="/users/update" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $user->id() ?>">
                <label class="field"><span class="field-label">Name</span><input name="name" required value="<?= htmlspecialchars($formState->value('name', $user->name()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                <label class="field"><span class="field-label">Email</span><input name="email" type="email" required value="<?= htmlspecialchars($formState->value('email', $user->email()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                <label class="field"><span class="field-label">Role</span>
                    <select name="role" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" <?= $formState->selected('role', $role, $role === $user->role()) ?>>
                                <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions"><button type="submit">Update</button><button type="button" class="button button-quiet" data-cancel-href="/users">Cancel</button></div>
            </form>
        <?php endif; ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
