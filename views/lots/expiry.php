<?php
// Expiry report (D-17, ADR-023): near-expiry and expired lots with stock, filters, CSV, over-reserved pairs and the
// expired write-off proposal. Admin/WarehouseStaff only (LotService).
$appTitle='Reports';$title='Expiry';$subtitle='Lots with stock that expire within '.$near_days.' days or have expired (business date '.$today.'). Expired lots are never sold, shipped or transferred; FEFO ships the earliest expiry first.';
$csvQuery=http_build_query(['warehouse_id'=>$filters['warehouse_id']>0?(string)$filters['warehouse_id']:'','status'=>$filters['status'],'q'=>$filters['q']]);
$toolbar='<a class="button" href="/reports/expiry.csv?'.htmlspecialchars($csvQuery,ENT_QUOTES,'UTF-8').'" data-export="server">Export CSV</a><a class="button" href="/lots">Lot Trace</a>';
require dirname(__DIR__).'/inventory-operations/start.php';
$statusLabels=['flagged'=>'Near expiry and expired','near'=>'Near expiry','expired'=>'Expired','all'=>'All lots with an expiry date'];
?>
<section class="metric-grid" aria-label="Expiry summary">
<article class="metric-card" data-expiry-metric="near"><span>Near-expiry lots</span><strong><?= (int)$summary['near_lots'] ?></strong><small class="field-hint"><?= (int)$summary['near_units'] ?> units</small></article>
<article class="metric-card" data-expiry-metric="expired"><span>Expired lots</span><strong><?= (int)$summary['expired_lots'] ?></strong><small class="field-hint"><?= (int)$summary['expired_units'] ?> units, not sellable</small></article>
<article class="metric-card" data-expiry-metric="over-reserved"><span>Reservations above sellable stock</span><strong><?= count($over_reserved) ?></strong><small class="field-hint">product/warehouse pairs</small></article>
</section>
<form method="get" action="/reports/expiry" class="filters">
<label>Search SKU, product or lot <input name="q" maxlength="120" value="<?= $escape($filters['q']) ?>"></label>
<label>Warehouse <select name="warehouse_id"><option value="">All warehouses</option><?php foreach($warehouses as $warehouse): ?><option value="<?= $warehouse->id() ?>" <?= $filters['warehouse_id']===$warehouse->id()?'selected':'' ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
<label>Status <select name="status"><?php foreach($statusLabels as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
<button type="submit">Apply Filters</button><a class="button" href="/reports/expiry">Reset</a>
</form>
<section class="table-scroll" aria-label="Expiry table"><table class="data-table" data-export="server"><thead><tr><th>Product</th><th>Warehouse</th><th>Lot</th><th>Expiry</th><th>Days left</th><th>Status</th><th>Quantity</th><th>Unit cost</th></tr></thead><tbody>
<?php foreach($result->items() as $row): ?><tr data-lot-status="<?= $escape($row['status']) ?>"><td><?= $escape($row['sku'].' — '.$row['product_name']) ?></td><td><?= $escape($row['warehouse_name']) ?></td><td><a href="/lots/show?id=<?= (int)$row['id'] ?>"><?= $escape($row['lot_code']) ?></a></td><td><?= $escape($row['expiry_date']) ?></td><td><?= $row['days_left']===null?'—':(int)$row['days_left'] ?></td><td><span class="status-badge <?= \App\Support\LotStatus::tone($row['status']) ?>"><?= $escape($row['status']) ?></span></td><td><?= number_format((int)$row['quantity'],0,',','.') ?> <?= $escape($row['base_unit']??'') ?></td><td><?= \App\Support\Money::rupiah((float)$row['unit_cost']) ?></td></tr><?php endforeach; ?>
<?php if($result->total()===0): ?><tr><td colspan="8">No lots match these filters.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath='/reports/expiry';$paginationLabel='Expiry lots';$paginationQuery=['q'=>$filters['q'],'warehouse_id'=>$filters['warehouse_id']>0?(string)$filters['warehouse_id']:'','status'=>$filters['status']];require dirname(__DIR__).'/partials/pagination.php'; ?>
<section class="card" aria-labelledby="over-reserved-title"><div class="card-body"><h2 id="over-reserved-title">Reservations above sellable stock</h2>
<p class="field-hint">Lots expired after these orders were approved. Nothing is cancelled automatically; issuing more than the non-expired stock is refused. Write off the expired lots and receive fresh stock, or close/cancel the remainder.</p>
<?php if($over_reserved===[]): ?><p data-over-reserved="0">No reservation exceeds the non-expired stock.</p><?php else: ?>
<section class="table-scroll"><table class="data-table" data-over-reserved="<?= count($over_reserved) ?>"><thead><tr><th>Product</th><th>Warehouse</th><th>On hand</th><th>Expired</th><th>Reserved</th><th>Short</th><th>Open orders</th></tr></thead><tbody>
<?php foreach($over_reserved as $row): $sellable=(int)$row['quantity']-(int)$row['expired_quantity']; ?><tr><td><?= $escape($row['sku'].' — '.$row['product_name']) ?></td><td><?= $escape($row['warehouse_name']) ?></td><td><?= (int)$row['quantity'] ?></td><td><?= (int)$row['expired_quantity'] ?></td><td><?= (int)$row['reserved_quantity'] ?></td><td><?= (int)$row['reserved_quantity']-$sellable ?></td><td><?= $escape($row['orders']??'') ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
</div></section>
<section class="card" aria-labelledby="write-off-title"><div class="card-body"><h2 id="write-off-title">Write off expired stock</h2>
<p class="field-hint">Creates an Adjustment proposal with one line per expired lot with stock in the warehouse (at most 100). Another Admin approves it; posting re-checks that every lot is still expired and unchanged, then removes the units with one ledger row per lot.</p>
<form method="post" action="/inventory-operations/expired-write-off" class="form"><input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>">
<label class="field"><span class="field-label">Warehouse</span><select name="warehouse_id" required><?php foreach($warehouses as $warehouse): if(!$warehouse->isActive()) { continue; } ?><option value="<?= $warehouse->id() ?>"><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Reason</span><textarea name="reason" maxlength="500" required></textarea></label>
<div class="form-actions"><button type="submit">Propose Write-off</button></div></form>
</div></section>
<?php require dirname(__DIR__).'/inventory-operations/end.php'; ?>
