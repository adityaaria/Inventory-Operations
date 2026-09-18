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
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
</head>
<body>
    <main class="page">
        <nav class="toolbar"><a href="/purchase-orders">Purchase Orders</a></nav>
        <h1><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if (($error ?? '') !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <p>Status: <?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?></p>
        <p>Supplier: <?= htmlspecialchars(($suppliers[$order->supplierId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></p>
        <p>Destination: <?= htmlspecialchars(($warehouses[$order->destinationWarehouseId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></p>

        <table>
            <thead><tr><th>Product ID</th><th>Quantity</th><th>Received</th><th>Remaining</th><th>Purchase Price</th><th>Receive</th></tr></thead>
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
    </main>
</body>
</html>
