<?php
// Recall/trace of one lot (D-17, ADR-023): receipt (PO, supplier), transfers, issues (SO, customer), adjustments and returns.
$appTitle='Recall';$title='Lot '.$lot['lot_code'];$subtitle=$lot['sku'].' — '.$lot['product_name'].' · '.$lot['warehouse_name'];$toolbar='<a class="button" href="/lots?q='.htmlspecialchars(rawurlencode((string)$lot['lot_code']),ENT_QUOTES,'UTF-8').'">Same lot code</a><a class="button" href="/products/show?id='.(int)$lot['product_id'].'">Product</a>';
require dirname(__DIR__).'/inventory-operations/start.php';
$describe=static function(array $row): string {
    return match(true) {
        $row['reference_type']==='PO'=>'Receipt from '.($row['po_number']??'PO #'.$row['reference_id']).' · supplier '.($row['supplier_name']??'—'),
        $row['reference_type']==='SO'=>'Issue to '.($row['customer_name']??'—').' · '.($row['so_number']??'SO #'.$row['reference_id']),
        $row['reference_type']==='TRANSFER'=>((int)$row['quantity_delta']<0?'Transfer out':'Transfer in').' · transfer #'.$row['reference_id'],
        $row['reference_type']==='SUPPLIER_RETURN'=>'Supplier return #'.$row['reference_id'],
        $row['reference_type']==='CUSTOMER_RETURN'=>'Customer return #'.$row['reference_id'],
        $row['reference_type']==='ADJUSTMENT'=>(($row['operation_purpose']??'')==='ExpiredWriteOff'?'Expired write-off':'Adjustment').' #'.$row['reference_id'],
        $row['reference_type']==='STOCK_COUNT'=>'Stock count #'.$row['reference_id'].' variance',
        default=>$row['reference_type'].' #'.$row['reference_id'],
    };
};
$change=static fn(array $row): int => match($row['movement_type']){'Receipt'=>(int)$row['quantity'],'Issue'=>-(int)$row['quantity'],default=>(int)$row['quantity_delta']};
$customers=[];foreach($movements as $row){ if($row['reference_type']==='SO'){ $customers[(string)($row['customer_name']??'—')]=($customers[(string)($row['customer_name']??'—')]??0)+(int)$row['quantity']; } }
?>
<dl class="detail-summary" aria-label="Lot">
<div><dt>Expiry</dt><dd><?= $escape($lot['expiry_date']??'No expiry') ?> <span class="status-badge <?= \App\Support\LotStatus::tone($lot['status']) ?>"><?= $escape($lot['status']) ?></span><?= $lot['days_left']!==null?' ('.(int)$lot['days_left'].' days)':'' ?></dd></div>
<div><dt>On hand</dt><dd><?= (int)$lot['quantity'] ?> <?= $escape($lot['base_unit']) ?></dd></div>
<div><dt>Received</dt><dd><?= $escape($lot['received_at']) ?> (<?= $escape($lot['source_type']) ?>)</dd></div>
<div><dt>Unit cost</dt><dd><?= \App\Support\Money::rupiah((float)$lot['unit_cost']) ?> per <?= $escape($lot['base_unit']) ?></dd></div>
<?php if((int)$lot['opening_quantity']>0): ?><div><dt>Opening (migrated)</dt><dd><?= (int)$lot['opening_quantity'] ?></dd></div><?php endif; ?>
</dl>
<?php if($customers!==[]): ?><section class="card" aria-label="Customers who received this lot"><div class="card-body"><h2>Customers who received this lot</h2><ul><?php foreach($customers as $name=>$units): ?><li data-recall-customer><?= $escape($name) ?>: <?= (int)$units ?> <?= $escape($lot['base_unit']) ?></li><?php endforeach; ?></ul></div></section><?php endif; ?>
<section class="table-scroll" aria-label="Lot movements"><h2>Movements</h2><table class="data-table"><thead><tr><th>Ledger</th><th>Date</th><th>Movement</th><th>Document</th><th>Change</th><th>By</th></tr></thead><tbody>
<?php foreach($movements as $row): ?><tr data-trace-reference="<?= $escape($row['reference_type']) ?>"><td>#<?= (int)$row['id'] ?></td><td><?= $escape($row['created_at']) ?></td><td><?= $escape($row['movement_type']) ?></td><td><?= $escape($describe($row)) ?></td><td><?= $change($row)>0?'+':'' ?><?= $change($row) ?></td><td><?= $escape($row['performed_by']??'') ?></td></tr><?php endforeach; ?>
<?php if($movements===[]): ?><tr><td colspan="6">No ledger movement references this lot<?= (int)$lot['opening_quantity']>0?' (its stock was migrated as opening quantity)':'' ?>.</td></tr><?php endif; ?>
</tbody></table></section>
<?php if($transfers!==[]): ?><section class="table-scroll" aria-label="Transfers"><h2>Transfers</h2><table class="data-table"><thead><tr><th>Transfer</th><th>Route</th><th>Status</th><th>Dispatched</th><th>Received</th><th>Damaged</th><th>Short</th><th>Linked lot</th></tr></thead><tbody>
<?php foreach($transfers as $row): $linked=(int)$row['source_lot_id']===(int)$lot['id']?$row['destination_lot_id']:$row['source_lot_id']; ?><tr><td><a href="/inventory-operations/show?id=<?= (int)$row['operation_id'] ?>">#<?= (int)$row['operation_id'] ?></a></td><td><?= $escape($row['source_name'].' → '.$row['destination_name']) ?></td><td><?= $escape($row['status']) ?></td><td><?= (int)$row['dispatched_quantity'] ?></td><td><?= $row['received_quantity']===null?'—':(int)$row['received_quantity'] ?></td><td><?= $row['damaged_quantity']===null?'—':(int)$row['damaged_quantity'] ?></td><td><?= $row['short_quantity']===null?'—':(int)$row['short_quantity'] ?></td><td><?= $linked===null?'—':'<a href="/lots/show?id='.(int)$linked.'">lot #'.(int)$linked.'</a>' ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
<?php if($same_code!==[]): ?><section class="table-scroll" aria-label="Same lot code"><h2>Other lots with code <?= $escape($lot['lot_code']) ?></h2><table class="data-table"><thead><tr><th>Lot</th><th>Product</th><th>Warehouse</th><th>Expiry</th><th>On hand</th></tr></thead><tbody>
<?php foreach($same_code as $row): ?><tr><td><a href="/lots/show?id=<?= (int)$row['id'] ?>">#<?= (int)$row['id'] ?></a></td><td><?= $escape($row['sku']) ?></td><td><?= $escape($row['warehouse_name']) ?></td><td><?= $escape($row['expiry_date']??'—') ?></td><td><?= (int)$row['quantity'] ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
<?php require dirname(__DIR__).'/inventory-operations/end.php'; ?>
