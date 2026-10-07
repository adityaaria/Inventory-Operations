<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Order - Inventory & Order Management</title>
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
    <?php $workspaceTitle = $order->orderNumber(); require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <?php
        $detailStatusClass = $order->status() === 'PartiallyReceived' ? 'partial' : strtolower(str_replace([' ', '_'], '-', $order->status()));
        $detailStatusTone = match ($order->status()) {
            'Received' => 'status-success',
            'PartiallyReceived', 'Ordered' => 'status-warning',
            'Cancelled' => 'status-danger',
            default => 'status-normal',
        };
        ?>
        <header class="page-header">
            <div><p class="app-title">Purchase Order</p><h1><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle">Review order lines and available receiving actions.</p></div>
            <nav class="toolbar"><a href="/purchase-orders">Purchase Orders</a></nav>
        </header>
        <?php if (($error ?? '') !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <dl class="detail-summary">
            <div><dt>Status</dt><dd><span class="status-badge <?= $detailStatusTone ?> status-<?= htmlspecialchars($detailStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?></span></dd></div>
            <div><dt>Supplier</dt><dd><?= htmlspecialchars(($suppliers[$order->supplierId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Destination</dt><dd><?= htmlspecialchars(($warehouses[$order->destinationWarehouseId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
        </dl>

        <div class="table-scroll" role="region" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
<table class="detail-table">
            <thead><tr><th scope="col">Product ID</th><th scope="col">Quantity</th><th scope="col">Received</th><th scope="col">Remaining</th><th scope="col">Purchase Price</th><th scope="col">Receive</th></tr></thead>
            <tbody>
                <?php foreach ($order->items() as $item): ?>
                    <tr>
                        <td><?= $item->productId() ?></td>
                        <td><?= $item->quantity() ?></td>
                        <td><?= $item->receivedQuantity() ?></td>
                        <td><?= $item->remainingQuantity() ?></td>
                        <td><?= number_format($item->purchasePrice(), 2) ?></td>
                        <td>
                            <?php if ($canReceive && in_array($order->status(), \App\Entity\PurchaseOrder::RECEIVABLE_STATUSES, true) && $item->remainingQuantity() > 0): ?>
                                <form method="post" action="/purchase-orders/receive">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= $order->id() ?>">
                                    <input type="hidden" name="item_id" value="<?= $item->id() ?>">
                                    <input name="quantity" type="number" min="1" max="<?= $item->remainingQuantity() ?>" required>
                                    <button type="submit">Receive</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>

        <div class="form-actions">
        <?php if ($canOrderOrCancel && $order->status() === \App\Entity\PurchaseOrder::STATUS_DRAFT): ?>
            <form method="post" action="/purchase-orders/order">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Mark Ordered</button>
            </form>
        <?php endif; ?>
        <?php if ($canOrderOrCancel && in_array($order->status(), [\App\Entity\PurchaseOrder::STATUS_DRAFT, \App\Entity\PurchaseOrder::STATUS_ORDERED], true)): ?>
            <form method="post" action="/purchase-orders/cancel">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Cancel</button>
            </form>
        <?php endif; ?>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
