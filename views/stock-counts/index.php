<?php
// Stock count sessions (D-18, ADR-024): list for Admin and WarehouseStaff. Counters find their counts with "Assigned to me".
$appTitle = 'Inventory Operations';
$title = 'Stock Counts';
$subtitle = 'Blind physical counts per warehouse: count, review variances, recount, approve and post the differences to the lots.';
$toolbar = ($canCreate ? '<a class="button-primary" href="/stock-counts/create">New Stock Count</a>' : '') . '<a class="button" href="/reports/stock-counts">Count Report</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$tone = static fn (string $status): string => match ($status) {
    'Posted' => 'status-success',
    'Approved', 'Submitted' => 'status-normal',
    'Counting', 'RecountRequested' => 'status-warning',
    'Cancelled' => 'status-danger',
    default => 'status-normal',
};
?>
<form method="get" action="/stock-counts" class="filters">
    <label>Status <select name="status"><option value="">All statuses</option><?php foreach (\App\Service\StockCountService::STATUSES as $state): ?><option value="<?= $state ?>" <?= $filters['status'] === $state ? 'selected' : '' ?>><?= $escape($state) ?></option><?php endforeach; ?></select></label>
    <label>Warehouse <select name="warehouse_id"><option value="">All warehouses</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= $warehouse->id() ?>" <?= $filters['warehouse_id'] === (string) $warehouse->id() ? 'selected' : '' ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
    <label><input type="checkbox" name="mine" value="1" <?= $filters['mine'] === '1' ? 'checked' : '' ?>> Assigned to me</label>
    <button type="submit">Apply Filters</button><a class="button" href="/stock-counts">Reset</a>
</form>
<section class="table-scroll" aria-label="Stock counts table"><table class="data-table"><thead><tr><th scope="col">Count</th><th scope="col">Warehouse</th><th scope="col">Scope</th><th scope="col">Counters</th><th scope="col">Progress</th><th scope="col">Status</th><th scope="col">Created</th></tr></thead><tbody>
<?php foreach ($result->items() as $row): ?>
<tr data-stock-count="<?= (int) $row['id'] ?>">
    <td><a href="/stock-counts/show?id=<?= (int) $row['id'] ?>">Count #<?= (int) $row['id'] ?></a><br><small class="field-hint"><?= $escape($row['reason']) ?></small></td>
    <td><?= $escape($row['warehouse_name'] ?? '') ?></td>
    <td><?= $escape($row['scope'] === 'Category' ? 'Category: ' . ($row['category_name'] ?? '') : ($row['scope'] === 'Products' ? 'Selected products' : 'All products')) ?></td>
    <td><?= $escape($row['counter_names'] ?? '') ?></td>
    <td><?= (int) $row['counted_lines'] ?> of <?= (int) $row['line_count'] ?> lines counted</td>
    <td><span class="status-badge <?= $tone((string) $row['status']) ?>"><?= $escape($row['status']) ?></span></td>
    <td><?= $escape($row['created_at']) ?></td>
</tr>
<?php endforeach; ?>
<?php if ($result->total() === 0): ?><tr><td colspan="7">No stock counts match these filters.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath = '/stock-counts'; $paginationLabel = 'Stock counts'; $paginationQuery = array_filter($filters, static fn ($value): bool => $value !== ''); require dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
