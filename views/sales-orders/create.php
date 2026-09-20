<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Sales Order - Inventory & Order Management</title>
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
        <h1>Create Sales Order</h1>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/sales-orders" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label>Order Number <input name="order_number" required value="SO-<?= date('YmdHis') ?>"></label>
            <label>
                Customer
                <select name="customer_id" required>
                    <?php foreach ($customers as $customer): ?>
                        <?php if ($customer->isActive()): ?>
                            <option value="<?= $customer->id() ?>"><?= htmlspecialchars($customer->name(), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Source Warehouse
                <select name="warehouse_id" required>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <?php if ($warehouse->isActive()): ?>
                            <option value="<?= $warehouse->id() ?>"><?= htmlspecialchars($warehouse->name(), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Product
                <select name="product_id" required>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product->id() ?>"><?= htmlspecialchars($product->sku() . ' - ' . $product->name(), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Quantity <input name="quantity" type="number" min="1" required></label>
            <label>Selling Price <input name="selling_price" type="number" min="0" step="0.01" required></label>
            <button type="submit">Create Draft</button>
            <button type="button" class="button button-quiet" data-cancel-href="/sales-orders">Cancel</button>
        </form>
    </main>
</body>
</html>
