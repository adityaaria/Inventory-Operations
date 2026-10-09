<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Purchase Order - Inventory & Order Management</title>
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
    <?php $workspaceTitle = 'Create Purchase Order'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header"><div><p class="app-title">Inventory Operations</p><h1>Create Purchase Order</h1><p class="page-subtitle">Create a draft purchase order for replenishment. Review supplier, quantities and prices for every item.</p></div><nav class="toolbar" aria-label="Page actions"><a href="/purchase-orders">Purchase Orders</a></nav></header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/purchase-orders" class="form" data-order-form <?= \App\Support\Html::draftAttributes('purchase-order') ?>>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label class="field"><span class="field-label">Order Number</span><input name="order_number" required value="<?= htmlspecialchars($formState->value('order_number', 'PO-' . date('YmdHis')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Supplier</span>
                <select name="supplier_id" required>
                    <?php foreach ($suppliers as $supplier): ?>
                        <?php if ($supplier->isActive()): ?>
                            <option value="<?= $supplier->id() ?>" <?= $formState->selected('supplier_id', $supplier->id(), false) ?>><?= htmlspecialchars($supplier->name(), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span class="field-label">Destination Warehouse</span>
                <select name="warehouse_id" required>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <?php if ($warehouse->isActive()): ?>
                            <option value="<?= $warehouse->id() ?>" <?= $formState->selected('warehouse_id', $warehouse->id(), false) ?>><?= htmlspecialchars($warehouse->name(), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php $priceField = 'purchase_price'; $priceLabel = 'Purchase Price'; ?>
            <div data-order-items>
                <?php $orderItem = dirname(__DIR__) . '/partials/order-item.php'; $itemState = $formState; $prefix = ''; require $orderItem; ?>
                <?php $next = 1; foreach (is_array($old['items'] ?? null) ? array_slice($old['items'], 0, 99, true) : [] as $line): if (!is_array($line)) { continue; } $itemState = new \App\Support\FormState($line); $prefix = 'items[' . $next++ . ']['; require $orderItem; endforeach; ?>
            </div>
            <button type="button" class="button button-quiet" data-order-add>Add product item</button>
            <template data-order-template><?php $itemState = new \App\Support\FormState(); $prefix = null; require $orderItem; ?></template>
            <div class="form-actions"><button type="submit">Create Draft</button><button type="button" class="button button-quiet" data-cancel-href="/purchase-orders">Cancel</button></div>
        </form>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
