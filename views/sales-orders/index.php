<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
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
    <script defer src="/assets/js/dialog.js"></script>
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
    <?php $workspaceTitle = 'Sales Orders'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Sales Orders</h1>
                <p class="page-subtitle">Search, filter, and review customer fulfillment orders.</p>
            </div>
            <nav class="toolbar" aria-label="Page actions">

                <?php if ($canCreate): ?><a href="/sales-orders/create">Create Sales Order</a><?php endif; ?>
            </nav>
        </header>
        <form method="get" action="/sales-orders" class="filters">
            <label class="field"><span class="field-label">Search</span><input name="q" placeholder="Search order or customer" value="<?= htmlspecialchars($criteria->term(), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="field"><span class="field-label">Status</span><select name="status">
                <option value="">All statuses</option>
                <?php foreach (['Draft', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled'] as $status): ?>
                    <option value="<?= $status ?>" <?= $criteria->status() === $status ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select></label>
            <?php require dirname(__DIR__) . '/partials/sort-state.php'; ?>
            <button type="submit">Filter</button>
        </form>
        <section class="table-scroll" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>">
<table class="data-table">
            <thead>
                <?php $sortPath = '/sales-orders'; $sortHeading = dirname(__DIR__) . '/partials/sort-heading.php'; ?>
                <tr><?php $sortKey = 'order_number'; $sortLabel = 'Order Number'; require $sortHeading; ?><?php $sortKey = 'party'; $sortLabel = 'Customer'; require $sortHeading; ?><?php $sortKey = 'warehouse'; $sortLabel = 'Warehouse'; require $sortHeading; ?><?php $sortKey = 'status'; $sortLabel = 'Status'; require $sortHeading; ?><?php $sortKey = 'order_date'; $sortLabel = 'Date'; require $sortHeading; ?><th>Action</th></tr>
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
                    $statusTone = match ($order->status()) {
                        'Approved', 'Fulfilled' => 'status-success',
                        'PendingApproval' => 'status-warning',
                        'Cancelled' => 'status-danger',
                        default => 'status-normal',
                    };
                    $customer = $customers[$order->customerId()] ?? null;
                    $warehouse = $warehouses[$order->sourceWarehouseId()] ?? null;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($customer === null ? 'Unknown' : $customer->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($warehouse === null ? 'Unknown' : $warehouse->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="status-badge <?= $statusTone ?> status-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($order->orderDate(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><a class="action-link" href="/sales-orders/show?id=<?= $order->id() ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</section>
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
                <strong class="pagination-current" aria-current="page">Page <?= $result->page() ?> of <?= $result->pages() ?></strong>
                <?php if ($result->page() < $result->pages()): ?><a href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Next</a><?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
            </div>
        </nav>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
