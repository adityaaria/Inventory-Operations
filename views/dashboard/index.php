<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Inventory & Order Management</title>
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
    <?php $workspaceTitle = 'Dashboard'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <?php
        $purchaseStatus = isset($dashboard['purchase_orders_by_status']) && is_array($dashboard['purchase_orders_by_status'])
            ? $dashboard['purchase_orders_by_status']
            : [];
        $salesStatus = isset($dashboard['sales_orders_by_status']) && is_array($dashboard['sales_orders_by_status'])
            ? $dashboard['sales_orders_by_status']
            : [];
        $purchaseChart = htmlspecialchars((string) json_encode($purchaseStatus), ENT_QUOTES, 'UTF-8');
        $salesChart = htmlspecialchars((string) json_encode($salesStatus), ENT_QUOTES, 'UTF-8');
        $dashboardRole = (string) $dashboard['role'];
        $canCreatePurchaseOrder = in_array($dashboardRole, [\App\Entity\User::ROLE_ADMIN, \App\Entity\User::ROLE_WAREHOUSE_STAFF], true);
        $canCreateSalesOrder = in_array($dashboardRole, [\App\Entity\User::ROLE_ADMIN, \App\Entity\User::ROLE_SALES], true);
        ?>
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Dashboard</h1>
                <p class="page-subtitle">Role: <?= htmlspecialchars((string) $dashboard['role'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <nav class="toolbar"><a href="/reports">Reports</a></nav>
        </header>
        <section class="metric-grid">
        <?php if ($purchaseStatus !== []): ?>
            <article class="metric-card">
                <span>Total Purchase Orders</span>
                <strong><?= array_sum($purchaseStatus) ?></strong>
            </article>
        <?php endif; ?>
        <?php if ($salesStatus !== []): ?>
            <article class="metric-card">
                <span>Total Sales Orders</span>
                <strong><?= array_sum($salesStatus) ?></strong>
            </article>
        <?php endif; ?>
        <?php if (isset($dashboard['inventory_value'])): ?>
            <article class="metric-card">
                <span>Inventory Value</span>
                <strong><?= number_format((float) $dashboard['inventory_value'], 2) ?></strong>
            </article>
            <?php if (isset($dashboard['low_stock_count'])): ?>
                <article class="metric-card">
                    <span>Low Stock Rows</span>
                    <strong><?= (int) $dashboard['low_stock_count'] ?></strong>
                </article>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (isset($dashboard['po_receipt_queue'])): ?>
            <article class="metric-card">
                <span>PO Receipt Queue</span>
                <strong><?= (int) $dashboard['po_receipt_queue'] ?></strong>
            </article>
            <?php if (isset($dashboard['so_issue_queue'])): ?>
                <article class="metric-card">
                    <span>SO Issue Queue</span>
                    <strong><?= (int) $dashboard['so_issue_queue'] ?></strong>
                </article>
            <?php endif; ?>
        <?php endif; ?>
        </section>
        <section class="dashboard-grid">
            <article class="dashboard-panel">
                <h2 id="purchase-order-status-heading">Purchase Order Status</h2>
                <div class="chart" data-chart="<?= $purchaseChart ?>" aria-labelledby="purchase-order-status-heading"></div>
            </article>
            <article class="dashboard-panel">
                <h2 id="sales-order-status-heading">Sales Order Status</h2>
                <div class="chart" data-chart="<?= $salesChart ?>" aria-labelledby="sales-order-status-heading"></div>
            </article>
            <article class="dashboard-panel">
                <h2>Purchase Status Data</h2>
                <?php if ($purchaseStatus === []): ?><div class="empty-state"><strong>No purchase order data</strong><span>Create purchase orders to populate this widget.</span></div><?php endif; ?>
                <?php if ($purchaseStatus !== []): ?>
                    <div class="table-scroll" role="region" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
<table class="data-table">
                        <thead><tr><th>Status</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($purchaseStatus as $status => $total): ?>
                                <tr><td><?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $total ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
</div>
                <?php endif; ?>
            </article>
            <aside class="quick-panel">
                <h2>Quick Actions</h2>
                <ul class="quick-list">
                    <li><a href="/products"><span>Review stock</span><strong>Products</strong></a></li>
                    <?php if ($canCreatePurchaseOrder): ?><li><a href="/purchase-orders/create"><span>Create replenishment</span><strong>PO</strong></a></li><?php endif; ?>
                    <?php if ($canCreateSalesOrder): ?><li><a href="/sales-orders/create"><span>Create fulfillment</span><strong>SO</strong></a></li><?php endif; ?>
                    <li><a href="/reports"><span>Download reports</span><strong>CSV</strong></a></li>
                </ul>
            </aside>
        </section>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
