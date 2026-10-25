<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
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
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
    <?php $formState = new \App\Support\FormState($old ?? []); ?>
    <?php $workspaceTitle = 'Create Customer'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Create Customer</h1><p class="page-subtitle">Add a customer for sales orders.</p></div><nav class="toolbar" aria-label="Page actions"><a href="/customers">Customers</a></nav></header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/customers" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label class="field"><span class="field-label">Name</span><input name="name" required value="<?= htmlspecialchars($formState->value('name', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Email</span><input name="email" type="email" value="<?= htmlspecialchars($formState->value('email', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Phone</span><input name="phone" value="<?= htmlspecialchars($formState->value('phone', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Address</span><textarea name="address" rows="3"><?= htmlspecialchars($formState->value('address', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
            <fieldset class="stock-proposal-item" data-customer-tax><legend>Tax identity (optional, printed on invoices)</legend>
                <label class="field"><span class="field-label">NPWP</span><input name="npwp" inputmode="numeric" maxlength="24" value="<?= htmlspecialchars($formState->value('npwp', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><span class="field-hint">15 or 16 digits; dots and dashes are ignored.</span></label>
                <label class="field"><span class="field-label">NIK</span><input name="nik" inputmode="numeric" maxlength="24" value="<?= htmlspecialchars($formState->value('nik', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><span class="field-hint">16 digits, for customers without NPWP.</span></label>
                <label class="field"><span class="field-label">Tax name</span><input name="tax_name" maxlength="120" value="<?= htmlspecialchars($formState->value('tax_name', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                <label class="field"><span class="field-label">Tax address</span><textarea name="tax_address" rows="2" maxlength="255"><?= htmlspecialchars($formState->value('tax_address', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
            </fieldset>
            <div class="form-actions"><button type="submit">Create</button><button type="button" class="button button-quiet" data-cancel-href="/customers">Cancel</button></div>
        </form>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
