<?php
// Inventory valuation FIFO per batch (D-20, ADR-026), Admin only: lot quantity × lot unit cost, current or as of a date,
// grouped by warehouse, category, product or lot, plus goods in transit at their lot cost.
$current = 'valuation';
require __DIR__ . '/nav.php';
$report ??= null;
$appTitle = 'Reports'; $title = 'Inventory Valuation';
$subtitle = $report === null ? 'Inventory at lot cost.' : 'FIFO per batch: every lot at its own unit cost (quantity × unit cost)' . ($report['as_of'] === '' ? ', current.' : ', as of the end of ' . $report['as_of'] . ' (' . \App\Support\BusinessDate::timezone()->getName() . ').');
$csvQuery = $report === null ? '' : http_build_query(['by' => $report['by'], 'as_of' => $report['as_of'], 'warehouse_id' => $report['filters']['warehouse_id'] ?: '', 'category_id' => $report['filters']['category_id'] ?: '', 'q' => $report['filters']['q']]);
$toolbar = '<a class="button" href="/reports/valuation.csv?' . htmlspecialchars($csvQuery, ENT_QUOTES, 'UTF-8') . '" data-export="server">Export CSV</a>' . $valuationNav;
require dirname(__DIR__) . '/inventory-operations/start.php';
?>
<?php if ($report !== null): ?>
<section class="metric-grid" aria-label="Valuation summary">
<article class="metric-card" data-valuation-metric="on-hand"><span>On hand at lot cost</span><strong><?= $rupiahCents($report['totals']['value_cents']) ?></strong><small class="field-hint"><?= number_format($report['totals']['quantity'], 0, ',', '.') ?> base units in <?= (int) $report['totals']['lots'] ?> lots</small></article>
<article class="metric-card" data-valuation-metric="in-transit"><span>In transit at lot cost</span><strong><?= $rupiahCents($report['transit_cents']) ?></strong><small class="field-hint"><?= number_format(array_sum(array_column($report['transit'], 'units')), 0, ',', '.') ?> units dispatched, not yet received<?= $report['unvalued_in_transit_units'] > 0 ? ' · ' . (int) $report['unvalued_in_transit_units'] . ' units dispatched before the valuation start (not valued)' : '' ?></small></article>
<article class="metric-card" data-valuation-metric="total"><span>Total inventory value</span><strong data-valuation-total><?= $rupiahCents($report['total_cents']) ?></strong><small class="field-hint">on hand + in transit</small></article>
</section>
<p class="field-hint" data-valuation-start><?= $escape($valuationStartNote($report['start'])) ?></p>
<form method="get" action="/reports/valuation" class="filters">
<label>Group by <select name="by"><?php foreach (\App\Service\InventoryValuationService::GROUPS as $value => $label): ?><option value="<?= $value ?>" <?= $report['by'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
<label>As of <input type="date" name="as_of" max="<?= $escape($report['today']) ?>" value="<?= $escape($report['as_of']) ?>"></label>
<label>Warehouse <select name="warehouse_id"><option value="">All warehouses</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= $warehouse->id() ?>" <?= $report['filters']['warehouse_id'] === $warehouse->id() ? 'selected' : '' ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
<label>Category <select name="category_id"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= $category->id() ?>" <?= $report['filters']['category_id'] === $category->id() ? 'selected' : '' ?>><?= $escape($category->name()) ?></option><?php endforeach; ?></select></label>
<label>Search SKU, product or lot <input name="q" maxlength="120" value="<?= $escape($report['filters']['q']) ?>"></label>
<button type="submit">Apply</button><a class="button" href="/reports/valuation">Reset</a>
</form>
<section class="table-scroll" aria-label="Valuation"><table class="data-table" data-valuation-rows="<?= (int) $report['row_count'] ?>"><thead><tr>
<?php if ($report['by'] === 'warehouse'): ?><th>Warehouse</th><?php elseif ($report['by'] === 'category'): ?><th>Category</th><?php elseif ($report['by'] === 'product'): ?><th>Product</th><th>Category</th><th>Warehouse</th><?php else: ?><th>Lot</th><th>Expiry</th><th>Product</th><th>Warehouse</th><th>Unit cost</th><?php endif; ?>
<th>Quantity</th><th>Lots</th><th>Value</th></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): ?><tr>
<?php if ($report['by'] === 'warehouse'): ?><td><?= $escape($row['warehouse_name']) ?></td><?php elseif ($report['by'] === 'category'): ?><td><?= $escape($row['category_name']) ?></td>
<?php elseif ($report['by'] === 'product'): ?><td><?= $escape($row['sku'] . ' — ' . $row['product_name']) ?></td><td><?= $escape($row['category_name']) ?></td><td><?= $escape($row['warehouse_name']) ?></td>
<?php else: ?><td><a href="/lots/show?id=<?= (int) $row['lot_id'] ?>"><?= $escape($row['lot_code']) ?></a></td><td><?= $escape($row['expiry_date'] ?? '—') ?></td><td><?= $escape($row['sku'] . ' — ' . $row['product_name']) ?></td><td><?= $escape($row['warehouse_name']) ?></td><td><?= \App\Support\Money::rupiah((float) $row['unit_cost']) ?></td><?php endif; ?>
<td><?= number_format((int) $row['quantity'], 0, ',', '.') ?><?= isset($row['base_unit']) ? ' ' . $escape($row['base_unit']) : '' ?></td><td><?= (int) $row['lots'] ?></td><td data-value-cents="<?= (int) $row['value_cents'] ?>"><?= $rupiahCents((int) $row['value_cents']) ?></td></tr>
<?php endforeach; ?>
<?php if ($report['rows'] === []): ?><tr><td colspan="8">No stock matches these filters.</td></tr><?php endif; ?>
</tbody></table></section>
<?php if ($report['pages'] > 1): $result = new \App\Support\PaginatedResult($report['rows'], (int) $report['row_count'], (int) $report['page'], \App\Service\InventoryValuationService::PAGE_SIZE); $paginationPath = '/reports/valuation'; $paginationLabel = 'Valuation rows'; $paginationQuery = ['by' => $report['by'], 'as_of' => $report['as_of'], 'warehouse_id' => $report['filters']['warehouse_id'] ?: '', 'category_id' => $report['filters']['category_id'] ?: '', 'q' => $report['filters']['q']]; require dirname(__DIR__) . '/partials/pagination.php'; endif; ?>
<section class="card" aria-labelledby="transit-value-title"><div class="card-body"><h2 id="transit-value-title">In transit (ADR-021)</h2>
<p class="field-hint">Units dispatched and not yet received, at the cost of the lot they left; they belong to no warehouse and are counted once, here.</p>
<?php if ($report['transit'] === []): ?><p data-transit-rows="0">Nothing in transit.</p><?php else: ?>
<section class="table-scroll"><table class="data-table" data-transit-rows="<?= count($report['transit']) ?>"><thead><tr><th>Transfer</th><th>From → To</th><th>Product</th><th>Lot</th><th>Units</th><th>Unit cost</th><th>Value</th></tr></thead><tbody>
<?php foreach ($report['transit'] as $row): ?><tr><td><a href="/inventory-operations/show?id=<?= (int) $row['operation_id'] ?>">#<?= (int) $row['operation_id'] ?></a></td><td><?= $escape($row['source_name'] . ' → ' . $row['destination_name']) ?></td><td><?= $escape($row['sku'] . ' — ' . $row['product_name']) ?></td><td><?= $escape($row['lot_code']) ?></td><td><?= (int) $row['units'] ?></td><td><?= \App\Support\Money::rupiah((float) $row['unit_cost']) ?></td><td><?= $rupiahCents((int) $row['value_cents']) ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
</div></section>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
