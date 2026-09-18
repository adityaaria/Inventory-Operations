<!doctype html>
<html lang="en">
<head>
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
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
</head>
<body>
    <main class="page">
        <h1>Edit User</h1>
        <?php if ($error !== ''): ?>
            <p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($user === null): ?>
            <p>User not found.</p>
        <?php else: ?>
            <form method="post" action="/users/update" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $user->id() ?>">
                <label>Name <input name="name" required value="<?= htmlspecialchars($user->name(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Email <input name="email" type="email" required value="<?= htmlspecialchars($user->email(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>
                    Role
                    <select name="role" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" <?= $role === $user->role() ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Update</button>
                <a href="/users">Cancel</a>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
