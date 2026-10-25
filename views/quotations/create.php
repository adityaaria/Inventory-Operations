<?php
// Create quotation (D-19, ADR-025): lines priced like SO lines; the list price follows the customer's price list.
$appTitle = 'Sales'; $title = 'Create Quotation'; $subtitle = 'Quote prices to a customer. Number QT-YYMM-NNNN is assigned when the quotation is saved.';
$toolbar = '<a class="button" href="/quotations">Quotations</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$formState = new \App\Support\FormState($old ?? []);
$priceResolution = true;
?>
<form method="post" action="/quotations" class="form" data-order-form data-price-resolution="/api/price-resolution" <?= \App\Support\Html::draftAttributes('quotation') ?>>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<label class="field"><span class="field-label">Customer</span><select name="customer_id" required data-price-customer><?php foreach ($customers as $customer): if (!$customer->isActive()) { continue; } ?><option value="<?= $customer->id() ?>" <?= $formState->selected('customer_id', $customer->id(), false) ?>><?= $escape($customer->name()) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Source Warehouse</span><select name="warehouse_id" required><?php foreach ($warehouses as $warehouse): if (!$warehouse->isActive()) { continue; } ?><option value="<?= $warehouse->id() ?>" <?= $formState->selected('warehouse_id', $warehouse->id(), false) ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Valid until</span><input name="valid_until" type="date" required value="<?= $escape($formState->value('valid_until', $validUntil)) ?>"><span class="field-hint">After this date the quotation shows as Expired and can no longer be accepted or converted.</span></label>
<label class="field"><span class="field-label">Notes (optional)</span><textarea name="notes" rows="2" maxlength="500"><?= $escape($formState->value('notes', '')) ?></textarea></label>
<?php $priceField = 'selling_price'; $priceLabel = 'Quoted Price'; ?>
<p class="field-hint" data-price-policy>Each line starts at the customer's list price (price list tier, else master price; prices exclude PPN). A different price needs a reason; it may not be below the purchase price, and Sales may discount at most <?= (int) $maxDiscountPercent ?>% below the list price.</p>
<div data-order-items>
<?php $orderItem = dirname(__DIR__) . '/partials/order-item.php'; $itemState = $formState; $prefix = ''; require $orderItem; ?>
<?php $next = 1; foreach (is_array($old['items'] ?? null) ? array_slice($old['items'], 0, 99, true) : [] as $line): if (!is_array($line)) { continue; } $itemState = new \App\Support\FormState($line); $prefix = 'items[' . $next++ . ']['; require $orderItem; endforeach; ?>
</div>
<button type="button" class="button button-quiet" data-order-add>Add product item</button>
<template data-order-template><?php $itemState = new \App\Support\FormState(); $prefix = null; require $orderItem; ?></template>
<div class="form-actions"><button type="submit">Create Quotation</button><button type="button" class="button button-quiet" data-cancel-href="/quotations">Cancel</button></div>
</form>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
