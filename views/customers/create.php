<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Customer - Inventory & Order Management</title>
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
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Create Customer</h1><p class="page-subtitle">Add a customer for sales orders.</p></div><nav class="toolbar"><a href="/customers">Customers</a></nav></header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/customers" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label class="field"><span class="field-label">Name</span><input name="name" required></label>
            <label class="field"><span class="field-label">Email</span><input name="email" type="email"></label>
            <label class="field"><span class="field-label">Phone</span><input name="phone"></label>
            <label class="field"><span class="field-label">Address</span><textarea name="address" rows="3"></textarea></label>
            <div class="form-actions"><button type="submit">Create</button><button type="button" class="button button-quiet" data-cancel-href="/customers">Cancel</button></div>
        </form>
    </main>
</body>
</html>
