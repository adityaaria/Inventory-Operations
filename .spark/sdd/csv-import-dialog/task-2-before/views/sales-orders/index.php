<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Orders - Inventory & Order Management</title>
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
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Sales Orders</h1>
                <p class="page-subtitle">Search, filter, and review customer fulfillment orders.</p>
            </div>
            <nav class="toolbar">
                <a href="/">Home</a>
                <a href="/sales-orders/create">Create Sales Order</a>
            </nav>
        </header>
        <form method="get" action="/sales-orders" class="filters">
            <input name="q" placeholder="Search order or customer" value="<?= htmlspecialchars($criteria->term(), ENT_QUOTES, 'UTF-8') ?>">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach (['Draft', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled'] as $status): ?>
                    <option value="<?= $status ?>" <?= $criteria->status() === $status ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select name="direction">
                <option value="desc" <?= $criteria->direction() === 'desc' ? 'selected' : '' ?>>Newest</option>
                <option value="asc" <?= $criteria->direction() === 'asc' ? 'selected' : '' ?>>Oldest</option>
            </select>
            <button type="submit">Filter</button>
        </form>
        <table class="data-table">
            <thead>
                <tr><th>Order Number</th><th>Customer</th><th>Warehouse</th><th>Status</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if ($result->items() === []): ?>
                    <tr class="empty">
                        <td colspan="6">No sales orders found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($result->items() as $order): ?>
                    <?php
                    $statusClass = $order->status() === 'PendingApproval'
                        ? 'pending'
                        : strtolower(str_replace([' ', '_'], '-', $order->status()));
                    $customer = $customers[$order->customerId()] ?? null;
                    $warehouse = $warehouses[$order->sourceWarehouseId()] ?? null;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($customer === null ? 'Unknown' : $customer->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($warehouse === null ? 'Unknown' : $warehouse->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="status-badge status-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($order->orderDate(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><a class="action-link" href="/sales-orders/show?id=<?= $order->id() ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $baseQuery = [
            'q' => $criteria->term(),
            'status' => $criteria->status(),
            'sort' => $criteria->sortBy(),
            'direction' => $criteria->direction(),
            'per_page' => $criteria->perPage(),
        ];
        $previousPageUrl = '/sales-orders?' . http_build_query($baseQuery + ['page' => max(1, $result->page() - 1)]);
        $nextPageUrl = '/sales-orders?' . http_build_query($baseQuery + ['page' => min($result->pages(), $result->page() + 1)]);
        ?>
        <nav class="pagination" aria-label="Sales orders pagination">
            <span class="pagination-summary">Total <?= $result->total() ?> sales orders</span>
            <div class="pagination-actions">
                <?php if ($result->page() > 1): ?><a href="<?= htmlspecialchars($previousPageUrl, ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
                <strong class="pagination-current">Page <?= $result->page() ?> of <?= $result->pages() ?></strong>
                <?php if ($result->page() < $result->pages()): ?><a href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Next</a><?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
            </div>
        </nav>
    </main>
</body>
</html>
