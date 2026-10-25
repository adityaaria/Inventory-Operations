<?php
$isTransfer=$operation['kind']==='Transfer';
$title=$operation['kind'].' #'.$operation['id'];
$subtitle=$isTransfer?'Goods leave the source warehouse at dispatch and enter the destination at receipt (D-15).':'Stock changes are applied only after independent approval.';
$toolbar='<a class="button" href="/timeline?kind=Operation&amp;id='.(int)$operation['id'].'">Transaction Timeline</a>'.($isTransfer?'<a class="button" href="/inventory-operations/in-transit">In Transit</a>':'').'<a class="button" href="/inventory-operations">Stock Operations</a>';
require __DIR__.'/start.php';
$items=$operation['items'];
// Quantities are stored in base units; each line keeps the unit it was entered in (D-16, ADR-022).
$unitSets=$unitSets??[];
$qty=static fn(int $product,mixed $base): string => $base===null?'—':(isset($unitSets[$product])?$unitSets[$product]->format((int)$base):(string)(int)$base);
$lineQty=static fn(array $item): string => ((int)($item['unit_factor']??1))>1?number_format((int)$item['unit_quantity'],0,',','.').' '.$item['unit_code'].' ('.number_format((int)$item['quantity'],0,',','.').' '.(isset($unitSets[(int)$item['product_id']])?$unitSets[(int)$item['product_id']]->baseCode():'').')':$qty((int)$item['product_id'],(int)$item['quantity']);
$baseCode=static fn(int $product): string => isset($unitSets[$product])?$unitSets[$product]->baseCode():'units';
$writtenOff=$isTransfer?array_sum(array_map(static fn(array $item): int => (int)($item['damaged_quantity']??0)+(int)($item['short_quantity']??0),$items)):0;
$badgeClass=match(true){
    in_array($operation['status'],['Rejected','Cancelled'],true)=>'status-danger',
    $operation['status']==='Dispatched'=>'status-in-transit',
    $operation['status']==='Received'&&$writtenOff>0=>'status-warning',
    in_array($operation['status'],['Posted','Received'],true)=>'status-success',
    default=>'status-pending',
};
$receiptInput=$receiptInput??['received'=>[],'damaged'=>[],'reason'=>''];
$canHandle=in_array($actor->role(),['Admin','WarehouseStaff'],true);
?>
<section class="card"><div class="card-body">
<p>Status: <span class="status-badge <?= $badgeClass ?>"><?= $escape($operation['status']==='Dispatched'?'In transit (Dispatched)':$operation['status']) ?></span><?php if($operation['status']==='Received'&&$writtenOff>0): ?> <span class="status-badge status-warning">Received with discrepancy</span><?php endif; ?></p>
<?php if(($operation['purpose']??'Standard')==='ExpiredWriteOff'): ?><p><span class="status-badge status-danger" data-expired-write-off>Expired stock write-off</span> One line per expired lot; posting re-checks that every lot is still expired and unchanged (D-17).</p><?php endif; ?>
<p>Reason: <?= $escape($operation['reason']) ?></p>
<p><?= $isTransfer?'From':'Warehouse' ?>: <?= $escape($operation['warehouse_name']??('#'.(int)$operation['warehouse_id'])) ?><?= $operation['destination_id']!==null?' → to '.$escape($operation['destination_name']??('#'.(int)$operation['destination_id'])):'' ?></p>
<p>Created by #<?= (int)$operation['created_by'] ?><?php if($operation['approved_by']!==null): ?> · Reviewed by #<?= (int)$operation['approved_by'] ?><?php endif; ?><?php if($operation['posted_by']!==null): ?> · Posted by #<?= (int)$operation['posted_by'] ?><?php endif; ?></p>
<?php if($operation['decision_reason']!==null): ?><p>Review/cancellation reason: <?= $escape($operation['decision_reason']) ?></p><?php endif; ?>
<?php if($operation['kind']==='CustomerReturn'): ?><p>Returned goods confirmed fit for stock.</p><?php endif; ?>
<?php if($isTransfer): ?>
<dl class="detail-summary" aria-label="Transfer progress">
<div><dt>Approved</dt><dd><?= $escape($operation['approved_at']??'—') ?></dd></div>
<div><dt>Dispatched</dt><dd><?= $escape(($operation['dispatched_at']??null)===null?'Not yet':$operation['dispatched_at'].(($operation['dispatcher_name']??null)!==null?' by '.$operation['dispatcher_name']:'')) ?></dd></div>
<div><dt>Received</dt><dd><?= $escape(($operation['received_at']??null)===null?'Not yet':$operation['received_at'].(($operation['receiver_name']??null)!==null?' by '.$operation['receiver_name']:'')) ?></dd></div>
<div><dt>Written off</dt><dd><?= $writtenOff ?> unit<?= $writtenOff===1?'':'s' ?></dd></div>
</dl>
<?php if(($operation['receipt_reason']??null)!==null): ?><p>Receipt discrepancy reason: <?= $escape($operation['receipt_reason']) ?></p><?php endif; ?>
<?php if($operation['status']==='Dispatched'): ?><p class="field-hint">These goods are in transit: they no longer count at the source and are not yet available at the destination. A dispatched transfer cannot be cancelled; receive it, entering damaged or missing units.</p><?php endif; ?>
<?php endif; ?>
</div></section>
<?php if($isTransfer): ?>
<section class="table-scroll" aria-label="Operation items"><table class="data-table"><thead><tr><th>Product</th><th>Quantity</th><th>Dispatched</th><th>In transit</th><th>Received (good)</th><th>Damaged</th><th>Short</th></tr></thead><tbody>
<?php foreach($items as $item): $dispatched=$item['dispatched_quantity']??null;$received=$item['received_quantity']??null;$transit=$dispatched!==null&&$received===null?(int)$dispatched:0; ?>
<?php $p=(int)$item['product_id']; ?><tr><td><?= $escape($item['sku'].' — '.$item['product_name']) ?></td><td><?= $escape($lineQty($item)) ?></td><td><?= $escape($qty($p,$dispatched)) ?></td><td><?= $escape($qty($p,$transit)) ?></td><td><?= $escape($qty($p,$received)) ?></td><td><?= $escape($qty($p,$item['damaged_quantity']??null)) ?></td><td><?= $escape($qty($p,$item['short_quantity']??null)) ?></td></tr>
<?php endforeach; ?></tbody></table></section>
<?php $transferLots=[];foreach($items as $item){foreach($item['lots']??[] as $lot){$transferLots[]=$lot+['sku'=>$item['sku']];}} ?>
<?php if($transferLots!==[]): ?><section class="table-scroll" aria-label="Transfer lots"><h2>Lots in this transfer</h2><table class="data-table"><thead><tr><th>Product</th><th>Lot</th><th>Expiry</th><th>Dispatched</th><th>Received (good)</th><th>Damaged</th><th>Short</th></tr></thead><tbody>
<?php foreach($transferLots as $lot): $p=(int)$lot['product_id']; ?><tr><td><?= $escape($lot['sku']) ?></td><td data-lot-code><a href="/lots/show?id=<?= (int)$lot['source_lot_id'] ?>"><?= $escape($lot['lot_code']) ?></a><?php if(($lot['destination_lot_id']??null)!==null): ?> → <a href="/lots/show?id=<?= (int)$lot['destination_lot_id'] ?>">destination lot</a><?php endif; ?></td><td><?= $escape($lot['expiry_date']??'—') ?></td><td><?= $escape($qty($p,$lot['dispatched_quantity'])) ?></td><td><?= $escape($qty($p,$lot['received_quantity']??null)) ?></td><td><?= $escape($qty($p,$lot['damaged_quantity']??null)) ?></td><td><?= $escape($qty($p,$lot['short_quantity']??null)) ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
<?php else: ?>
<section class="table-scroll" aria-label="Operation items"><table class="data-table"><thead><tr><th>Product</th><th>Lot</th><th><?= $operation['kind']==='Adjustment'?'Counted quantity':'Quantity' ?></th><th>Count baseline</th><th>Original ledger</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?= $escape($item['sku'].' — '.$item['product_name']) ?></td><td data-lot-code><?php if(($item['lot_id']??null)!==null): ?><a href="/lots/show?id=<?= (int)$item['lot_id'] ?>"><?= $escape($item['counted_lot_code']??('#'.(int)$item['lot_id'])) ?></a><?= ($item['counted_lot_expiry']??null)!==null?' (exp. '.$escape($item['counted_lot_expiry']).')':'' ?><?php elseif(($item['lot_code']??null)!==null): ?>New lot <?= $escape($item['lot_code']) ?><?= ($item['expiry_date']??null)!==null?' (exp. '.$escape($item['expiry_date']).')':'' ?><?php elseif($operation['kind']==='Adjustment'): ?>Whole product<?php else: ?>Original movement's lot<?php endif; ?></td><td><?= $escape($lineQty($item)) ?></td><td><?= $escape($qty((int)$item['product_id'],$item['baseline'])) ?></td><td><?= $item['source_ledger_id']===null?'—':'#'.(int)$item['source_ledger_id'] ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php endif; ?>
<?php if($operation['status']==='PendingApproval' && $actor->role()==='Admin' && $actor->userId()!==(int)$operation['created_by']): ?>
<form method="post" action="/inventory-operations/decide" class="form"><input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>"><input type="hidden" name="id" value="<?= (int)$operation['id'] ?>"><label class="field"><span class="field-label">Approval reason</span><textarea name="reason" maxlength="500" required></textarea></label><div class="form-actions"><button type="button" class="button-danger" data-dialog-open="reject-dialog">Reject</button><button type="submit" name="decision" value="Approved">Approve</button></div></form>
<?php $dialog=['id'=>'reject-dialog','title'=>'Reject '.$operation['kind'].' #'.(int)$operation['id'],'intro'=>'The proposal is closed as Rejected and no stock changes are made. The reason is shown on this page and in the timeline.','action'=>'/inventory-operations/decide','hidden'=>['id'=>(int)$operation['id'],'decision'=>'Rejected'],'label'=>'Rejection reason','submit'=>'Reject Proposal']+($rejectDialog??[]);require dirname(__DIR__).'/partials/reason-dialog.php'; ?>
<?php endif; ?>
<?php if($operation['status']==='PendingApproval' && $actor->userId()===(int)$operation['created_by']): ?><p class="alert">Another Admin must review this proposal.</p><?php endif; ?>
<?php if($operation['status']==='Approved' && !$isTransfer): ?><form method="post" action="/inventory-operations/post" class="form"><input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>"><input type="hidden" name="id" value="<?= (int)$operation['id'] ?>"><div class="form-actions"><button type="submit">Post Stock Changes</button></div></form><?php endif; ?>
<?php if($operation['status']==='Approved' && $isTransfer && $canHandle): ?><form method="post" action="/inventory-operations/dispatch" class="form"><input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>"><input type="hidden" name="id" value="<?= (int)$operation['id'] ?>"><p class="field-hint">Dispatch removes the goods from <?= $escape($operation['warehouse_name']??'the source') ?> (available stock only; reserved stock stays with approved sales orders). They stay in transit until <?= $escape($operation['destination_name']??'the destination') ?> receives them.</p><div class="form-actions"><button type="submit">Dispatch Transfer</button></div></form><?php endif; ?>
<?php if($operation['status']==='Dispatched' && $canHandle): ?>
<form method="post" action="/inventory-operations/receive" class="form" aria-label="Receive transfer"><input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>"><input type="hidden" name="id" value="<?= (int)$operation['id'] ?>">
<h2>Receive at <?= $escape($operation['destination_name']??'destination') ?></h2>
<p class="field-hint">Enter the good and damaged quantities per line in the product's base unit (shown next to each field). Units not entered as good or damaged are recorded as short. Only good units enter stock; damaged and short units are written off and need a reason.</p>
<section class="table-scroll"><table class="data-table"><thead><tr><th>Product</th><th>Dispatched</th><th>Received (good)</th><th>Damaged</th></tr></thead><tbody>
<?php foreach($items as $item): $product=(int)$item['product_id'];$dispatched=(int)$item['dispatched_quantity']; ?>
<tr><td><?= $escape($item['sku'].' — '.$item['product_name']) ?></td><td><?= $escape($qty($product,$dispatched)) ?></td>
<td><label class="visually-hidden" for="received-<?= $product ?>">Good units of <?= $escape($item['sku']) ?></label><input id="received-<?= $product ?>" name="received[<?= $product ?>]" type="number" min="0" max="<?= $dispatched ?>" step="1" inputmode="numeric" required value="<?= $escape($receiptInput['received'][$product]??$dispatched) ?>"> <span class="field-hint"><?= $escape($baseCode($product)) ?></span></td>
<td><label class="visually-hidden" for="damaged-<?= $product ?>">Damaged units of <?= $escape($item['sku']) ?></label><input id="damaged-<?= $product ?>" name="damaged[<?= $product ?>]" type="number" min="0" max="<?= $dispatched ?>" step="1" inputmode="numeric" value="<?= $escape($receiptInput['damaged'][$product]??0) ?>"> <span class="field-hint"><?= $escape($baseCode($product)) ?></span></td></tr>
<?php endforeach; ?></tbody></table></section>
<label class="field"><span class="field-label">Discrepancy reason (required when anything is damaged or short)</span><textarea name="receipt_reason" maxlength="500"><?= $escape($receiptInput['reason']) ?></textarea></label>
<div class="form-actions"><button type="submit">Receive Transfer</button></div></form>
<?php endif; ?>
<?php if(in_array($operation['status'],['PendingApproval','Approved'],true) && ($actor->role()==='Admin'||$actor->userId()===(int)$operation['created_by'])): ?><form method="post" action="/inventory-operations/decide" class="form"><input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>"><input type="hidden" name="id" value="<?= (int)$operation['id'] ?>"><input type="hidden" name="decision" value="Cancelled"><label class="field"><span class="field-label">Cancellation reason</span><textarea name="reason" maxlength="500" required></textarea></label><div class="form-actions"><button type="submit" class="button button-quiet">Cancel Proposal</button></div></form><?php endif; ?>
<?php require __DIR__.'/end.php'; ?>
