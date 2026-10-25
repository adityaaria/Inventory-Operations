<?php
// One price list with its tiers and customers (D-19, ADR-025), Admin only.
$appTitle = 'Price List'; $title = $list['name']; $subtitle = 'Tier = the highest minimum quantity not above the line quantity, in the line unit. Prices exclude PPN and may not be below the current purchase price per unit.';
$toolbar = '<a class="button" href="/price-lists">Price Lists</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$formState = new \App\Support\FormState($old ?? []);
$validNow = $list['is_active'] && ($list['valid_from'] === null || $list['valid_from'] <= $list['today']) && ($list['valid_to'] === null || $list['valid_to'] >= $list['today']);
$assignedIds = array_map(static fn ($customer): int => $customer->id(), $list['customers']);
?>
<dl class="detail-summary">
<div><dt>Status</dt><dd><span class="status-badge <?= $validNow ? 'status-success' : 'status-danger' ?>" data-price-list-state><?= $validNow ? 'In use today' : ($list['is_active'] ? 'Outside its validity period' : 'Inactive') ?></span></dd></div>
<div><dt>Valid</dt><dd><?= $escape(($list['valid_from'] ?? 'open') . ' – ' . ($list['valid_to'] ?? 'open')) ?></dd></div>
<div><dt>Items</dt><dd><?= count($list['items']) ?></dd></div><div><dt>Customers</dt><dd><?= count($list['customers']) ?></dd></div>
</dl>
<section class="card" aria-labelledby="edit-list"><div class="card-body"><h2 id="edit-list">Edit list</h2>
<form method="post" action="/price-lists/update" class="form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $list['id'] ?>">
<label class="field"><span class="field-label">Name</span><input name="name" required maxlength="120" value="<?= $escape($list['name']) ?>"></label>
<label class="field"><span class="field-label">Description</span><input name="description" maxlength="255" value="<?= $escape($list['description']) ?>"></label>
<label class="field"><span class="field-label">Valid from</span><input name="valid_from" type="date" value="<?= $escape($list['valid_from'] ?? '') ?>"></label>
<label class="field"><span class="field-label">Valid to</span><input name="valid_to" type="date" value="<?= $escape($list['valid_to'] ?? '') ?>"></label>
<label class="field"><input type="checkbox" name="is_active" value="1"<?= $list['is_active'] ? ' checked' : '' ?>> Active (unticking deactivates the list; existing orders keep their prices)</label>
<div class="form-actions"><button type="submit">Save</button></div>
</form></div></section>
<section class="table-scroll" aria-label="Price list items"><table class="data-table" data-price-list-items><thead><tr><th>Product</th><th>Unit</th><th>From quantity</th><th>Unit price</th><th>Change</th><th>Remove</th></tr></thead><tbody>
<?php foreach ($list['items'] as $item): ?>
<tr><td><?= $escape($item['sku'] . ' — ' . $item['product_name']) ?></td><td><?= $escape($item['unit_code']) ?></td><td><?= (int) $item['min_quantity'] ?></td><td><?= \App\Support\Money::rupiah((float) $item['unit_price']) ?></td>
<td><form method="post" action="/price-lists/items/update" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $list['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
<label class="visually-hidden" for="min-<?= (int) $item['id'] ?>">From quantity</label><input id="min-<?= (int) $item['id'] ?>" name="min_quantity" type="number" min="1" step="1" value="<?= (int) $item['min_quantity'] ?>">
<label class="visually-hidden" for="price-<?= (int) $item['id'] ?>">Unit price</label><input id="price-<?= (int) $item['id'] ?>" name="unit_price" type="number" min="0" step="0.01" value="<?= $escape($item['unit_price']) ?>"><button type="submit">Save</button></form></td>
<td><form method="post" action="/price-lists/items/delete"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $list['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><button type="submit" class="button-danger">Remove</button></form></td></tr>
<?php endforeach; ?>
<?php if ($list['items'] === []): ?><tr><td colspan="6">No tiers yet.</td></tr><?php endif; ?>
</tbody></table></section>
<section class="card" aria-labelledby="add-item"><div class="card-body"><h2 id="add-item">Add tier</h2>
<form method="post" action="/price-lists/items" class="form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $list['id'] ?>">
<label class="field"><span class="field-label">Product</span><select name="product_id" required><?php foreach ($products as $product): ?><option value="<?= $product->id() ?>" <?= $formState->selected('product_id', $product->id(), false) ?>><?= $escape($product->sku() . ' - ' . $product->name()) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Unit code</span><input name="unit_code" maxlength="30" placeholder="empty = base unit" value="<?= $escape($formState->value('unit_code', '')) ?>"><span class="field-hint">A unit of the product, for example karton, box or pcs. Tiers match the line unit exactly.</span></label>
<label class="field"><span class="field-label">From quantity (in that unit)</span><input name="min_quantity" type="number" min="1" step="1" required value="<?= $escape($formState->value('min_quantity', '1')) ?>"></label>
<label class="field"><span class="field-label">Unit price (Rp, excl. PPN)</span><input name="unit_price" type="number" min="0" step="0.01" required value="<?= $escape($formState->value('unit_price', '')) ?>"></label>
<div class="form-actions"><button type="submit">Add Tier</button></div>
</form></div></section>
<section class="card" aria-labelledby="customers-title"><div class="card-body"><h2 id="customers-title">Customers on this list</h2>
<ul data-price-list-customers><?php foreach ($list['customers'] as $customer): ?><li><?= $escape($customer->name()) ?> <form method="post" action="/price-lists/assign" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $list['id'] ?>"><input type="hidden" name="customer_id" value="<?= $customer->id() ?>"><input type="hidden" name="remove" value="1"><button type="submit" class="button-quiet">Remove</button></form></li><?php endforeach; ?>
<?php if ($list['customers'] === []): ?><li>No customer is assigned.</li><?php endif; ?></ul>
<form method="post" action="/price-lists/assign" class="form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $list['id'] ?>">
<label class="field"><span class="field-label">Assign customer</span><select name="customer_id" required><?php foreach ($allCustomers as $customer): if (in_array($customer->id(), $assignedIds, true)) { continue; } ?><option value="<?= $customer->id() ?>"><?= $escape($customer->name() . ($customer->priceListId() !== null ? ' (moves from another list)' : '')) ?></option><?php endforeach; ?></select></label>
<div class="form-actions"><button type="submit">Assign</button></div>
</form></div></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
