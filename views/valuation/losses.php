<?php
// Inventory losses at lot cost (D-20, ADR-026), Admin only: expired write-offs (ADR-023), transit damage and shortage
// (ADR-021, no ledger row: units × the lot cost carried by the transfer) and negative count variances (ADR-024).
$current = 'losses';
require __DIR__ . '/nav.php';
$appTitle = 'Reports'; $title = 'Inventory Losses';
$subtitle = 'Business dates ' . $report['from'] . ' to ' . $report['to'] . ', valued at the cost of the lots lost.';
$toolbar = '<a class="button" href="/reports/inventory-losses.csv?' . htmlspecialchars(http_build_query(['from' => $report['from'], 'to' => $report['to']]), ENT_QUOTES, 'UTF-8') . '" data-export="server">Export CSV</a>' . $valuationNav;
require dirname(__DIR__) . '/inventory-operations/start.php';
?>
<section class="metric-grid" aria-label="Losses summary">
<?php foreach (\App\Service\InventoryValuationService::LOSS_KINDS as $kind => $label): $total = $report['totals'][$kind]; ?>
<article class="metric-card" data-loss-metric="<?= $escape($kind) ?>"><span><?= $escape($label) ?></span><strong><?= $rupiahCents($total['value_cents']) ?></strong><small class="field-hint"><?= (int) $total['units'] ?> units · <?= (int) $total['events'] ?> rows</small></article>
<?php endforeach; ?>
<article class="metric-card" data-loss-metric="total"><span>Total losses</span><strong data-losses-total="<?= (int) $report['total_cents'] ?>"><?= $rupiahCents($report['total_cents']) ?></strong></article>
</section>
<form method="get" action="/reports/inventory-losses" class="filters"><label>From <input type="date" name="from" value="<?= $escape($report['from']) ?>"></label><label>To <input type="date" name="to" value="<?= $escape($report['to']) ?>"></label><button type="submit">Apply</button></form>
<section class="table-scroll" aria-label="Losses"><table class="data-table" data-loss-rows="<?= count($report['rows']) ?>"><thead><tr><th>Date</th><th>Kind</th><th>Document</th><th>Warehouse</th><th>Product</th><th>Lot</th><th>Units</th><th>Unit cost</th><th>Value</th></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): $href = match ($row['document_type']) { 'STOCK_COUNT' => '/stock-counts/show?id=', default => '/inventory-operations/show?id=' }; ?>
<tr data-loss-kind="<?= $escape($row['kind']) ?>"><td><?= $escape(\App\Service\InventoryValuationService::label($row['occurred_at'])) ?></td><td><?= $escape($row['kind_label']) ?></td><td><a href="<?= $href . (int) $row['document_id'] ?>"><?= $escape($row['document_type'] . ' #' . $row['document_id']) ?></a></td><td><?= $escape($row['warehouse_name']) ?></td><td><?= $escape($row['sku'] . ' — ' . $row['product_name']) ?></td><td><?= $escape($row['lot_code']) ?></td><td><?= (int) $row['units'] ?></td><td><?= \App\Support\Money::rupiah((float) $row['unit_cost']) ?></td><td><?= $rupiahCents((int) $row['value_cents']) ?></td></tr>
<?php endforeach; ?>
<?php if ($report['rows'] === []): ?><tr><td colspan="9">No loss in this period.</td></tr><?php endif; ?>
</tbody></table></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
