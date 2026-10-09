<?php $title='Create Stock Proposal';$subtitle='Posting requires approval from another Admin. Return goods must use their original movement and warehouse.';$toolbar='';require __DIR__.'/start.php';$state=new \App\Support\FormState($old??[]); ?>
<form method="post" action="/inventory-operations" class="form" data-stock-proposal <?= \App\Support\Html::draftAttributes('stock-proposal') ?>>
<input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token']??'') ?>">
<label class="field"><span class="field-label">Type</span><select name="kind" required><?php foreach(\App\Service\BusinessOperationService::KINDS as $kind): ?><option value="<?= $kind ?>" <?= $state->selected('kind',$kind,false) ?>><?= $kind ?></option><?php endforeach; ?></select></label>
<label class="field" data-stock-field="regular"><span class="field-label">Warehouse</span><select name="warehouse_id"><?php foreach($warehouses as $warehouse):if(!$warehouse->isActive()) { continue; } ?><option value="<?= $warehouse->id() ?>" <?= $state->selected('warehouse_id',$warehouse->id(),false) ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
<label class="field" data-stock-field="transfer"><span class="field-label">Destination Warehouse</span><select name="destination_id"><?php foreach($warehouses as $warehouse):if(!$warehouse->isActive()) { continue; } ?><option value="<?= $warehouse->id() ?>" <?= $state->selected('destination_id',$warehouse->id(),false) ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
<div data-stock-items>
<?php $stockItem=__DIR__.'/item.php';$itemState=$state;$prefix='';require $stockItem;
$extra=is_array($old['items']??null)?array_slice($old['items'],0,99,true):[];
$next=1;foreach($extra as $line):if(!is_array($line)) { continue; }$itemState=new \App\Support\FormState($line);$prefix='items['.$next++.'][';require $stockItem;endforeach; ?>
</div>
<button type="button" class="button button-quiet" data-stock-add>Add product item</button>
<template data-stock-template><?php $itemState=new \App\Support\FormState();$prefix='';require $stockItem; ?></template>
<label class="field"><span class="field-label">Reason</span><textarea name="reason" maxlength="500" required><?= $escape($state->value('reason','')) ?></textarea></label>
<div class="form-actions"><button type="button" class="button button-quiet" data-cancel-href="/inventory-operations">Cancel</button><button type="submit">Submit Proposal</button></div>
</form><?php require __DIR__.'/end.php'; ?>
