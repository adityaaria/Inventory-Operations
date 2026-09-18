<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Order - Inventory & Order Management</title>
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
        <nav class="toolbar"><a href="/sales-orders">Sales Orders</a></nav>
        <h1><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <p>Status: <?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?></p>
        <p>Customer: <?= htmlspecialchars(($customers[$order->customerId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></p>
        <p>Source: <?= htmlspecialchars(($warehouses[$order->sourceWarehouseId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></p>

        <table>
            <thead><tr><th>Product ID</th><th>Quantity</th><th>Selling Price</th></tr></thead>
            <tbody>
                <?php foreach ($order->items() as $item): ?>
                    <tr>
                        <td><?= $item->productId() ?></td>
                        <td><?= $item->quantity() ?></td>
                        <td><?= number_format($item->sellingPrice(), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($canSubmit): ?>
            <form method="post" action="/sales-orders/submit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Submit</button>
            </form>
        <?php endif; ?>
        <?php if ($canApproveOrCancel && $order->status() === \App\Entity\SalesOrder::STATUS_PENDING_APPROVAL): ?>
            <form method="post" action="/sales-orders/approve">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Approve</button>
            </form>
        <?php endif; ?>
        <?php if ($canApproveOrCancel && $order->status() !== \App\Entity\SalesOrder::STATUS_FULFILLED && $order->status() !== \App\Entity\SalesOrder::STATUS_CANCELLED): ?>
            <form method="post" action="/sales-orders/cancel">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Cancel</button>
            </form>
        <?php endif; ?>
        <?php if ($canIssue && $order->status() === \App\Entity\SalesOrder::STATUS_APPROVED): ?>
            <form method="post" action="/sales-orders/issue">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Issue Goods</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
