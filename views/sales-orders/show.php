<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
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
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
    <?php $workspaceTitle = $order->orderNumber(); require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <?php
        $detailStatusClass = $order->status() === 'PendingApproval' ? 'pending' : strtolower(str_replace([' ', '_'], '-', $order->status()));
        $detailStatusTone = match ($order->status()) {
            'Approved', 'Fulfilled' => 'status-success',
            'PendingApproval' => 'status-warning',
            'Cancelled' => 'status-danger',
            default => 'status-normal',
        };
        ?>
        <header class="page-header">
            <div><p class="app-title">Sales Order</p><h1><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle">Review order lines and fulfillment actions.</p></div>
            <nav class="toolbar" aria-label="Document actions"><a href="/timeline?kind=SO&amp;id=<?= $order->id() ?>">Transaction Timeline</a><a href="/sales-orders">Sales Orders</a></nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <dl class="detail-summary">
            <div><dt>Status</dt><dd><span class="status-badge <?= $detailStatusTone ?> status-<?= htmlspecialchars($detailStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?></span></dd></div>
            <div><dt>Customer</dt><dd><?= htmlspecialchars(($customers[$order->customerId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Source</dt><dd><?= htmlspecialchars(($warehouses[$order->sourceWarehouseId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
        </dl>

        <div class="table-scroll" role="region" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
<table class="detail-table">
            <thead><tr><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col">Selling Price</th></tr></thead>
            <tbody>
                <?php foreach ($order->items() as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($productLabels[$item->productId()] ?? ('Product #' . $item->productId()), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $item->quantity() ?></td>
                        <td><?= number_format($item->sellingPrice(), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>

        <?php if(($rejection??null)!==null): ?><p class="alert">Rejected: <?= htmlspecialchars($rejection['reason'],ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
        <?php if($canApproveOrCancel && $order->status()==='PendingApproval'): ?><form method="post" action="/sales-orders/reject" class="form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($GLOBALS['csrf_token']??''),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="id" value="<?= $order->id() ?>"><label class="field"><span class="field-label">Rejection reason</span><textarea name="reason" maxlength="500" required></textarea></label><div class="form-actions"><button type="submit">Reject Order</button></div></form><?php endif; ?>
        <?php if($canIssue && ($movements??[])!==[]): ?><section class="card"><div class="card-body"><h2>Issues and Customer Returns</h2><?php foreach($movements as $movement): ?><p>Issue #<?= $movement->id() ?> · quantity <?= $movement->quantity() ?> <a href="/inventory-operations/create?kind=CustomerReturn&amp;source_ledger_id=<?= $movement->id() ?>">Propose Customer Return</a></p><?php endforeach; ?></div></section><?php endif; ?>
        <div class="form-actions">
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
                <input type="hidden" name="operation_key" value="<?= bin2hex(random_bytes(16)) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Issue Goods</button>
            </form>
        <?php endif; ?>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
