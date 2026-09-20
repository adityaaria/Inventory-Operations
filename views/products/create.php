<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Product - Inventory & Order Management</title>
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
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Create Product</h1><p class="page-subtitle">Add a product and its stock policy.</p></div><nav class="toolbar"><a href="/products">Products</a></nav></header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/products" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label class="field"><span class="field-label">SKU</span><input name="sku" required></label>
            <label class="field"><span class="field-label">Name</span><input name="name" required></label>
            <label class="field"><span class="field-label">Unit</span><input name="unit" required></label>
            <label class="field"><span class="field-label">Purchase Price</span><input name="purchase_price" type="number" min="0" step="0.01" required></label>
            <label class="field"><span class="field-label">Selling Price</span><input name="selling_price" type="number" min="0" step="0.01" required></label>
            <label class="field"><span class="field-label">Reorder Point</span><input name="reorder_point" type="number" min="0" required></label>
            <label class="field">
                <span class="field-label">Category</span>
                <select name="category_id" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category->id() ?>"><?= htmlspecialchars($category->name(), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form-actions"><button type="submit">Create</button><button type="button" class="button button-quiet" data-cancel-href="/products">Cancel</button></div>
        </form>
    </main>
</body>
</html>
