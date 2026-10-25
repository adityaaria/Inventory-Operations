<?php
// Stock count history and variance summary (D-18, ADR-024): posted counts only, quantity and value (lot unit cost ×
// posted variance). The ledger rows of these postings appear in Stock Movements with reference STOCK_COUNT.
$appTitle = 'Reports';
$title = 'Stock Count Report';
$subtitle = 'History of posted counts with the variances they applied: units gained and lost, and their value at the lot unit cost.';
$csvQuery = http_build_query(['warehouse_id' => $filters['warehouse_id'] > 0 ? (string) $filters['warehouse_id'] : '', 'from' => $filters['from'], 'to' => $filters['to']]);
$toolbar = '<a class="button" href="/reports/stock-counts.csv?' . htmlspecialchars($csvQuery, ENT_QUOTES, 'UTF-8') . '" data-export="server">Export CSV</a><a class="button" href="/stock-counts">Stock Counts</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$money = static fn ($value): string => \App\Support\Money::rupiah((float) $value);
$signed = static fn (int $value): string => ($value > 0 ? '+' : '') . number_format($value, 0, ',', '.');
?>
<form method="get" action="/reports/stock-counts" class="filters">
    <label>Warehouse <select name="warehouse_id"><option value="">All warehouses</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= $warehouse->id() ?>" <?= $filters['warehouse_id'] === $warehouse->id() ? 'selected' : '' ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
    <label>Posted from <input type="date" name="from" value="<?= $escape($filters['from']) ?>"></label>
    <label>Posted to <input type="date" name="to" value="<?= $escape($filters['to']) ?>"></label>
    <button type="submit">Apply Filters</button><a class="button" href="/reports/stock-counts">Reset</a>
</form>
<section class="metric-grid" aria-label="Variance summary" data-count-report>
    <article class="metric-card"><span>Posted counts</span><strong data-report-counts><?= (int) $totals['counts'] ?></strong><small class="field-hint"><?= (int) $totals['lines'] ?> lines, <?= (int) $totals['recounted_lines'] ?> recounted, <?= (int) $totals['found_lots'] ?> found lots</small></article>
    <article class="metric-card"><span>Lines with a variance</span><strong><?= (int) $totals['variance_lines'] ?></strong></article>
    <article class="metric-card"><span>Units gained / lost</span><strong><?= $signed((int) $totals['units_gained']) ?> / <?= $signed(-(int) $totals['units_lost']) ?></strong><small class="field-hint">net <?= $signed((int) $totals['net_units']) ?> base units</small></article>
    <article class="metric-card"><span>Net value impact</span><strong data-report-value><?= $money($totals['net_value']) ?></strong><small class="field-hint">absolute <?= $money($totals['absolute_value']) ?></small></article>
</section>
<section class="table-scroll" aria-label="Count history"><h2>Count history</h2><table class="data-table" data-export="server"><thead><tr><th scope="col">Count</th><th scope="col">Warehouse</th><th scope="col">Scope</th><th scope="col">Posted</th><th scope="col">Lines</th><th scope="col">Variance lines</th><th scope="col">Recounted</th><th scope="col">Found lots</th><th scope="col">Gained</th><th scope="col">Lost</th><th scope="col">Net value</th></tr></thead><tbody>
<?php foreach ($history as $row): ?>
<tr data-report-count="<?= (int) $row['id'] ?>"><td><a href="/stock-counts/show?id=<?= (int) $row['id'] ?>">Count #<?= (int) $row['id'] ?></a></td><td><?= $escape($row['warehouse_name']) ?></td><td><?= $escape($row['scope'] === 'Category' ? (string) ($row['category_name'] ?? 'Category') : $row['scope']) ?></td><td><?= $escape($row['posted_at']) ?></td><td><?= (int) $row['line_count'] ?></td><td><?= (int) $row['variance_lines'] ?></td><td><?= (int) $row['recounted_lines'] ?></td><td><?= (int) $row['found_lots'] ?></td><td><?= $signed((int) $row['units_gained']) ?></td><td><?= $signed(-(int) $row['units_lost']) ?></td><td><?= $money($row['net_value']) ?></td></tr>
<?php endforeach; ?>
<?php if ($history === []): ?><tr><td colspan="11">No posted count matches these filters.</td></tr><?php endif; ?>
</tbody></table></section>
<section class="table-scroll" aria-label="Variance lines"><h2>Variance lines</h2><table class="data-table"><thead><tr><th scope="col">Count</th><th scope="col">Product</th><th scope="col">Lot</th><th scope="col">Expected</th><th scope="col">Counted</th><th scope="col">Variance</th><th scope="col">Unit cost</th><th scope="col">Value</th></tr></thead><tbody>
<?php foreach (array_slice($lines, 0, 100) as $row): ?>
<tr><td>#<?= (int) $row['count_id'] ?></td><td><?= $escape($row['sku'] . ' — ' . $row['product_name']) ?></td><td><?php if ($row['lot_id'] !== null): ?><a href="/lots/show?id=<?= (int) $row['lot_id'] ?>"><?= $escape($row['lot_code']) ?></a><?php else: ?><?= $escape($row['lot_code']) ?><?php endif; ?><?= $row['origin'] === 'Found' ? ' <span class="status-badge status-warning">Found</span>' : '' ?><?= (int) $row['recounted'] === 1 ? ' <span class="status-badge status-normal">Recounted</span>' : '' ?></td><td><?= (int) $row['expected_quantity'] ?> <?= $escape($row['base_unit']) ?></td><td><?= (int) $row['counted_quantity'] ?> <?= $escape($row['base_unit']) ?></td><td class="<?= (int) $row['variance'] > 0 ? 'variance-positive' : 'variance-negative' ?>"><?= $signed((int) $row['variance']) ?></td><td><?= $money($row['unit_cost']) ?></td><td><?= $money((int) $row['variance'] * (float) $row['unit_cost']) ?></td></tr>
<?php endforeach; ?>
<?php if ($lines === []): ?><tr><td colspan="8">No variance was posted.</td></tr><?php elseif (count($lines) > 100): ?><tr><td colspan="8">Showing 100 of <?= count($lines) ?> lines; export the CSV for all.</td></tr><?php endif; ?>
</tbody></table></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
