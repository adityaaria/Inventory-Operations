<?php
// Lot recall/trace search (D-17, ADR-023), Admin/WarehouseStaff.
$appTitle='Recall';$title='Lot Trace';$subtitle='Search a lot code (or SKU) to trace its receipt, transfers, shipments and returns.';$toolbar='<a class="button" href="/reports/expiry">Expiry Report</a>';
require dirname(__DIR__).'/inventory-operations/start.php'; ?>
<form method="get" action="/lots" class="filters"><label>Lot code or SKU <input name="q" maxlength="120" required value="<?= $escape($query) ?>"></label><button type="submit">Search</button><a class="button" href="/lots">Reset</a></form>
<?php if($query!==''): ?>
<section class="table-scroll" aria-label="Lots"><table class="data-table"><thead><tr><th>Lot</th><th>Product</th><th>Warehouse</th><th>Expiry</th><th>Status</th><th>Quantity</th><th>Origin</th></tr></thead><tbody>
<?php foreach($result->items() as $row): ?><tr><td><a href="/lots/show?id=<?= (int)$row['id'] ?>"><?= $escape($row['lot_code']) ?></a></td><td><?= $escape($row['sku'].' — '.$row['product_name']) ?></td><td><?= $escape($row['warehouse_name']) ?></td><td><?= $escape($row['expiry_date']??'—') ?></td><td><span class="status-badge <?= \App\Support\LotStatus::tone($row['status']) ?>"><?= $escape($row['status']) ?></span></td><td><?= (int)$row['quantity'] ?></td><td><?= $escape($row['source_type']) ?></td></tr><?php endforeach; ?>
<?php if($result->total()===0): ?><tr><td colspan="7">No lot matches this search.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath='/lots';$paginationLabel='Lots';$paginationQuery=['q'=>$query];require dirname(__DIR__).'/partials/pagination.php'; ?>
<?php else: ?><p class="field-hint">Enter a lot code printed on the goods, for example from a supplier recall notice.</p><?php endif; ?>
<?php require dirname(__DIR__).'/inventory-operations/end.php'; ?>
