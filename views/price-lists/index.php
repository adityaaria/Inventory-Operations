<?php
// Customer price lists (D-19, ADR-025), Admin only.
$appTitle = 'Pricing'; $title = 'Price Lists'; $subtitle = 'Customer price lists with quantity tiers per product and unit. A customer has at most one list; lines without a matching tier use the master price.';
$toolbar = '<a class="button" href="/customers">Customers</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$formState = new \App\Support\FormState($old ?? []);
?>
<section class="table-scroll" aria-label="Price lists"><table class="data-table" data-price-lists><thead><tr><th>Name</th><th>Status</th><th>Valid from</th><th>Valid to</th><th>Items</th><th>Customers</th></tr></thead><tbody>
<?php foreach ($lists as $list): ?>
<tr><td><a href="/price-lists/show?id=<?= (int) $list['id'] ?>"><?= $escape($list['name']) ?></a><?php if ($list['description'] !== ''): ?><br><small><?= $escape($list['description']) ?></small><?php endif; ?></td>
<td><span class="status-badge <?= $list['is_active'] ? 'status-success' : 'status-danger' ?>"><?= $list['is_active'] ? 'Active' : 'Inactive' ?></span></td>
<td><?= $escape($list['valid_from'] ?? 'Open') ?></td><td><?= $escape($list['valid_to'] ?? 'Open') ?></td><td><?= (int) $list['item_count'] ?></td><td><?= (int) $list['customer_count'] ?></td></tr>
<?php endforeach; ?>
<?php if ($lists === []): ?><tr><td colspan="6">No price list yet.</td></tr><?php endif; ?>
</tbody></table></section>
<section class="card" aria-labelledby="new-price-list"><div class="card-body"><h2 id="new-price-list">New price list</h2>
<form method="post" action="/price-lists" class="form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<label class="field"><span class="field-label">Name</span><input name="name" required maxlength="120" value="<?= $escape($formState->value('name', '')) ?>"></label>
<label class="field"><span class="field-label">Description</span><input name="description" maxlength="255" value="<?= $escape($formState->value('description', '')) ?>"></label>
<label class="field"><span class="field-label">Valid from (optional)</span><input name="valid_from" type="date" value="<?= $escape($formState->value('valid_from', '')) ?>"></label>
<label class="field"><span class="field-label">Valid to (optional)</span><input name="valid_to" type="date" value="<?= $escape($formState->value('valid_to', '')) ?>"></label>
<label class="field"><input type="checkbox" name="is_active" value="1" checked> Active</label>
<div class="form-actions"><button type="submit">Create Price List</button></div>
</form></div></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
