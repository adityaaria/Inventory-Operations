<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Customer - Inventory & Order Management</title>
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
        <h1>Edit Customer</h1>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($customer !== null): ?>
            <form method="post" action="/customers/update" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $customer->id() ?>">
                <label>Name <input name="name" required value="<?= htmlspecialchars($customer->name(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Email <input name="email" type="email" value="<?= htmlspecialchars($customer->email(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Phone <input name="phone" value="<?= htmlspecialchars($customer->phone(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Address <textarea name="address" rows="3"><?= htmlspecialchars($customer->address(), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                <button type="submit">Update</button>
                <button type="button" class="button" data-cancel-href="/customers">Cancel</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
