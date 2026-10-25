<?php
// New stock count (D-18, ADR-024; Admin). The snapshot is taken when the count is started, not now.
$appTitle = 'Stock Counts';
$title = 'New Stock Count';
$subtitle = 'Choose the warehouse, the scope and who counts. Counters count blind: they never see system quantities while counting.';
$toolbar = '<a class="button" href="/stock-counts">Stock Counts</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$oldValue = static fn (string $key): string => is_scalar($old[$key] ?? null) ? (string) $old[$key] : '';
$oldList = static fn (string $key): array => array_map('strval', is_array($old[$key] ?? null) ? $old[$key] : []);
$scope = $oldValue('scope') !== '' ? $oldValue('scope') : 'Category';
?>
<form method="post" action="/stock-counts" class="form" data-stock-count-form>
    <input type="hidden" name="csrf_token" value="<?= $escape($GLOBALS['csrf_token'] ?? '') ?>">
    <label class="field"><span class="field-label">Warehouse</span><select name="warehouse_id" required><?php foreach ($warehouses as $warehouse): ?><option value="<?= $warehouse->id() ?>" <?= $oldValue('warehouse_id') === (string) $warehouse->id() ? 'selected' : '' ?>><?= $escape($warehouse->name()) ?></option><?php endforeach; ?></select></label>
    <fieldset class="field"><legend class="field-label">Scope</legend>
        <?php foreach (['All' => 'All active products of the warehouse', 'Category' => 'One category', 'Products' => 'Selected products (at most ' . \App\Service\StockCountService::MAX_PRODUCTS . ')'] as $value => $label): ?>
            <label><input type="radio" name="scope" value="<?= $value ?>" <?= $scope === $value ? 'checked' : '' ?> required> <?= $escape($label) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="field"><span class="field-label">Category (scope "One category")</span><select name="category_id"><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= $category->id() ?>" <?= $oldValue('category_id') === (string) $category->id() ? 'selected' : '' ?>><?= $escape($category->name()) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="field-label">Products (scope "Selected products"; hold Ctrl or Cmd to select several)</span><select name="product_ids[]" multiple size="8"><?php foreach ($products as $product): ?><option value="<?= $product->id() ?>" <?= in_array((string) $product->id(), $oldList('product_ids'), true) ? 'selected' : '' ?>><?= $escape($product->sku() . ' — ' . $product->name()) ?></option><?php endforeach; ?></select></label>
    <fieldset class="field"><legend class="field-label">Counters (Admin or Warehouse Staff; the approving Admin must not count)</legend>
        <?php foreach ($counters as $user): ?>
            <label><input type="checkbox" name="counter_ids[]" value="<?= $user->id() ?>" <?= in_array((string) $user->id(), $oldList('counter_ids'), true) ? 'checked' : '' ?>> <?= $escape($user->name() . ' (' . $user->role() . ')') ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="field"><span class="field-label">Reason / notes</span><textarea name="reason" maxlength="500" required><?= $escape($oldValue('reason')) ?></textarea></label>
    <div class="form-actions"><button type="submit" class="button-primary">Create Count</button><a class="button" href="/stock-counts">Cancel</a></div>
</form>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
