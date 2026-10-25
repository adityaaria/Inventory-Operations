<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
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
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
    <?php $workspaceTitle = 'Product Details'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div><h1><?= htmlspecialchars($product->name(), ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle"><?= htmlspecialchars($product->sku(), ENT_QUOTES, 'UTF-8') ?></p></div>
            <nav class="toolbar" aria-label="Page actions"><a href="/products">Products</a><?php if ($canWrite): ?><a href="/products/edit?id=<?= $product->id() ?>">Edit Product</a><?php endif; ?></nav>
        </header>
        <?php
        // Stock is stored in base units and shown with the unit breakdown (D-16, ADR-022).
        $units = $units ?? \App\Support\UnitSet::forProduct($product);
        $qty = static fn (int $base): string => htmlspecialchars($units->format($base), ENT_QUOTES, 'UTF-8');
        ?>
        <dl class="detail-summary" aria-label="Product details">
            <div><dt>Category</dt><dd><?= htmlspecialchars($category?->name() ?? 'Unavailable', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Base Unit</dt><dd><?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Purchase Price</dt><dd><?= \App\Support\Money::rupiah($product->purchasePrice()) ?> per <?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Selling Price</dt><dd><?= \App\Support\Money::rupiah($product->sellingPrice()) ?> per <?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Default Reorder Point</dt><dd><?= $qty($product->reorderPoint()) ?></dd></div>
            <div><dt>Status</dt><dd><span class="status-badge <?= $product->isActive() ? 'status-normal' : 'status-cancelled' ?>"><?= $product->isActive() ? 'Active' : 'Inactive' ?></span></dd></div>
            <div><dt>Total Stock</dt><dd><?= $qty(array_sum(array_map(static fn ($stock): int => $stock->quantity(), $stocks))) ?></dd></div>
            <div><dt>Reserved</dt><dd><?= $qty(array_sum(array_map(static fn ($stock): int => $stock->reservedQuantity(), $stocks))) ?></dd></div>
            <div><dt>Available</dt><dd><?= $qty(array_sum(array_map(static fn ($stock): int => $stock->availableQuantity(), $stocks))) ?></dd></div>
            <div><dt>Expired (not sellable)</dt><dd data-expired-total><?= $qty(array_sum(array_map(static fn ($stock): int => $stock->expiredQuantity(), $stocks))) ?></dd></div>
            <div><dt>Tracks Expiry</dt><dd><?= $product->tracksExpiry() ? 'Yes' : 'No' ?></dd></div>
            <div><dt>In Transit</dt><dd><?= $qty(array_sum(array_map(static fn ($stock): int => $stock->inTransitInbound(), $stocks))) ?></dd></div>
        </dl>
        <h2>Stock by Warehouse</h2>
        <p class="field-hint">Quantity is the stock on hand. Reserved is held for approved sales orders until they are issued or cancelled; Available = Quantity − Reserved − units of expired lots (expired stock is never sold, D-17). In Transit (inbound) counts goods of dispatched transfers on their way to that warehouse; it is informational and never added to Quantity or Available. A warehouse is low on stock when its available quantity is below its reorder point. The reorder point is the warehouse override when set, otherwise the product default.</p>
        <?php $error = $error ?? ''; $errorWarehouseId = $errorWarehouseId ?? 0; $attemptedOverride = $attemptedOverride ?? ''; ?>
        <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <section class="table-scroll" aria-label="Product stock table">
            <table class="data-table">
                <thead><tr><th>Warehouse</th><th>Quantity</th><th>Reserved</th><th>Available</th><th>In Transit (inbound)</th><th>Reorder Point</th><th>Source</th><th>Status</th><?php if ($canWrite): ?><th data-no-sort>Warehouse Override</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if ($stocks === []): ?><tr class="empty"><td colspan="<?= $canWrite ? 9 : 8 ?>">No warehouse stock recorded for this product.</td></tr><?php endif; ?>
                <?php foreach ($stocks as $stock): ?>
                    <?php $tracked = $stock->isWarehouseActive() && $stock->isProductActive(); ?>
                    <tr>
                        <td><?= htmlspecialchars($stock->warehouseName(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $qty($stock->quantity()) ?></td>
                        <td><?= $qty($stock->reservedQuantity()) ?></td>
                        <td><?= $qty($stock->availableQuantity()) ?></td>
                        <td><?= $qty($stock->inTransitInbound()) ?></td>
                        <td><?= $qty($stock->effectiveReorderPoint()) ?></td>
                        <td><?= $stock->hasReorderPointOverride() ? 'Warehouse override' : 'Product default' ?></td>
                        <td><span class="status-badge <?= !$tracked ? 'status-inactive' : ($stock->isLowStock() ? 'stock-low' : 'status-normal') ?>"><?= !$tracked ? 'Inactive' : ($stock->isLowStock() ? 'Low stock' : 'Normal') ?></span></td>
                        <?php if ($canWrite): ?>
                        <td>
                            <?php $fieldId = 'reorder-override-' . $stock->warehouseId(); $value = $errorWarehouseId === $stock->warehouseId() ? $attemptedOverride : (string) ($stock->reorderPointOverride() ?? ''); ?>
                            <form method="post" action="/products/reorder-point" class="reorder-override-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= $product->id() ?>">
                                <input type="hidden" name="warehouse_id" value="<?= $stock->warehouseId() ?>">
                                <label class="visually-hidden" for="<?= $fieldId ?>">Reorder point override for <?= htmlspecialchars($stock->warehouseName(), ENT_QUOTES, 'UTF-8') ?> (empty uses product default)</label>
                                <input id="<?= $fieldId ?>" name="reorder_point_override" type="number" min="0" max="<?= \App\Support\ReorderThreshold::MAX ?>" step="1" inputmode="numeric" placeholder="Default <?= $product->reorderPoint() ?> <?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <button type="submit">Save</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php $lots = $lots ?? []; $canTraceLots = $canTraceLots ?? false; ?>
        <h2>Lots</h2>
        <p class="field-hint">Batches with stock per warehouse, earliest expiry first (FEFO order: sales orders ship the earliest non-expired lot first). Near expiry = at most <?= (int) ($lotNearDays ?? 30) ?> days left; expired lots are not sellable.</p>
        <section class="table-scroll" aria-label="Product lots table">
            <table class="data-table">
                <thead><tr><th>Warehouse</th><th>Lot</th><th>Expiry</th><th>Days left</th><th>Status</th><th>Quantity</th><th>Received</th><?php if ($canTraceLots): ?><th>Unit cost</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if ($lots === []): ?><tr class="empty"><td colspan="<?= $canTraceLots ? 8 : 7 ?>">No lots with stock.</td></tr><?php endif; ?>
                <?php foreach ($lots as $lot): ?>
                    <tr data-lot-status="<?= htmlspecialchars((string) $lot['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <td><?= htmlspecialchars((string) $lot['warehouse_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?php if ($canTraceLots): ?><a href="/lots/show?id=<?= (int) $lot['id'] ?>"><?= htmlspecialchars((string) $lot['lot_code'], ENT_QUOTES, 'UTF-8') ?></a><?php else: ?><?= htmlspecialchars((string) $lot['lot_code'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></td>
                        <td><?= htmlspecialchars((string) ($lot['expiry_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $lot['days_left'] === null ? '—' : (int) $lot['days_left'] ?></td>
                        <td><span class="status-badge <?= \App\Support\LotStatus::tone((string) $lot['status']) ?>"><?= htmlspecialchars((string) $lot['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= $qty((int) $lot['quantity']) ?></td>
                        <td><?= htmlspecialchars((string) $lot['received_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php if ($canTraceLots): ?><td><?= \App\Support\Money::rupiah((float) ($lot['unit_cost'] ?? 0)) ?> per <?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php require dirname(__DIR__) . '/partials/product-units.php'; ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
