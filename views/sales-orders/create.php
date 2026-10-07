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
    <?php $formState = new \App\Support\FormState($old ?? []); ?>
    <?php $workspaceTitle = 'Create Sales Order'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Create Sales Order</h1><p class="page-subtitle">Create a draft sales order for fulfillment.</p></div><nav class="toolbar"><a href="/sales-orders">Sales Orders</a></nav></header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/sales-orders" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label class="field"><span class="field-label">Order Number</span><input name="order_number" required value="<?= htmlspecialchars($formState->value('order_number', 'SO-' . date('YmdHis')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Customer</span>
                <select name="customer_id" required>
                    <?php foreach ($customers as $customer): ?>
                        <?php if ($customer->isActive()): ?>
                            <option value="<?= $customer->id() ?>" <?= $formState->selected('customer_id', $customer->id(), false) ?>><?= htmlspecialchars($customer->name(), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span class="field-label">Source Warehouse</span>
                <select name="warehouse_id" required>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <?php if ($warehouse->isActive()): ?>
                            <option value="<?= $warehouse->id() ?>" <?= $formState->selected('warehouse_id', $warehouse->id(), false) ?>><?= htmlspecialchars($warehouse->name(), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span class="field-label">Product</span>
                <select name="product_id" required>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product->id() ?>" <?= $formState->selected('product_id', $product->id(), false) ?>><?= htmlspecialchars($product->sku() . ' - ' . $product->name(), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span class="field-label">Quantity</span><input name="quantity" type="number" min="1" required value="<?= htmlspecialchars($formState->value('quantity', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Selling Price</span><input name="selling_price" type="number" min="0" step="0.01" required value="<?= htmlspecialchars($formState->value('selling_price', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <div class="form-actions"><button type="submit">Create Draft</button><button type="button" class="button button-quiet" data-cancel-href="/sales-orders">Cancel</button></div>
        </form>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
