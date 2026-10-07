<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product Details - Inventory & Order Management</title>
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
    <?php $workspaceTitle = 'Product Details'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div><h1><?= htmlspecialchars($product->name(), ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle"><?= htmlspecialchars($product->sku(), ENT_QUOTES, 'UTF-8') ?></p></div>
            <nav class="toolbar"><a href="/products">Products</a><?php if ($canWrite): ?><a href="/products/edit?id=<?= $product->id() ?>">Edit Product</a><?php endif; ?></nav>
        </header>
        <dl class="detail-summary" aria-label="Product details">
            <div><dt>Category</dt><dd><?= htmlspecialchars($category?->name() ?? 'Unavailable', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Unit</dt><dd><?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Purchase Price</dt><dd><?= number_format($product->purchasePrice(), 2) ?></dd></div>
            <div><dt>Selling Price</dt><dd><?= number_format($product->sellingPrice(), 2) ?></dd></div>
            <div><dt>Reorder Point</dt><dd><?= $product->reorderPoint() ?></dd></div>
            <div><dt>Status</dt><dd><span class="status-badge <?= $product->isActive() ? 'status-normal' : 'status-cancelled' ?>"><?= $product->isActive() ? 'Active' : 'Inactive' ?></span></dd></div>
            <div><dt>Total Stock</dt><dd><?= array_sum(array_map(static fn ($stock): int => $stock->quantity(), $stocks)) ?></dd></div>
        </dl>
        <h2>Stock by Warehouse</h2>
        <div class="table-scroll" role="region" aria-label="Product stock table" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Warehouse</th><th>Quantity</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ($stocks === []): ?><tr class="empty"><td colspan="3">No warehouse stock recorded for this product.</td></tr><?php endif; ?>
                <?php foreach ($stocks as $stock): ?><tr><td><?= htmlspecialchars($stock->warehouseName(), ENT_QUOTES, 'UTF-8') ?></td><td><?= $stock->quantity() ?></td><td><span class="status-badge <?= $stock->isLowStock() ? 'stock-low' : 'status-normal' ?>"><?= $stock->isLowStock() ? 'Low stock' : 'Normal' ?></span></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
