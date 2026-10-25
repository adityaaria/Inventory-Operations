<?php
// Units of measure of one product (D-16, ADR-022). Expects $product, $units (UnitSet), $unitUsage (unit id => used on
// documents), $unitForm (error/unit_id/input after a refused change) and $canManageUnits (Admin; the service enforces it).
/** @var \App\Entity\Product $product */
/** @var \App\Support\UnitSet $units */
$unitUsage = $unitUsage ?? [];
$unitForm = $unitForm ?? [];
$canManageUnits = $canManageUnits ?? false;
$unitError = (string) ($unitForm['error'] ?? '');
$unitErrorId = (int) ($unitForm['unit_id'] ?? 0);
$unitInput = $unitForm['input'] ?? [];
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$priceText = static fn (?float $own, float $derived): string => $own === null ? \App\Support\Money::rupiah($derived) . ' (base × factor)' : \App\Support\Money::rupiah($own);
$csrf = $e((string) ($GLOBALS['csrf_token'] ?? ''));
$value = static fn (string $field, string $default, int $unitId) => $unitErrorId === $unitId && $unitError !== '' && array_key_exists($field, $unitInput) ? (string) $unitInput[$field] : $default;
?>
<section aria-labelledby="product-units-title" data-product-units>
    <h2 id="product-units-title">Units of Measure</h2>
    <p class="field-hint">Stock is kept in the base unit (<?= $e($units->baseCode()) ?>). Orders and stock operations may use any active unit; quantities are converted with the factor and stored in <?= $e($units->baseCode()) ?>. A unit without its own price uses the base price × factor. Once a unit is used on a document its code and factor are fixed: deactivate it and add a new unit instead.</p>
    <?php if ($unitError !== ''): ?><p class="alert alert-danger" role="alert" data-unit-error><?= $e($unitError) ?></p><?php endif; ?>
    <section class="table-scroll" aria-label="Product units table">
        <table class="data-table">
            <thead><tr><th>Unit</th><th>Factor</th><th>Barcode</th><th>Purchase Price per Unit</th><th>Selling Price per Unit</th><th>Default</th><th>Status</th><?php if ($canManageUnits): ?><th data-no-sort>Change</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($units->all() as $unit): ?>
                <tr data-unit="<?= $e($unit->code()) ?>">
                    <td><?= $e($unit->code()) ?><?= $unit->isBase() ? ' <span class="status-badge status-normal">Base</span>' : '' ?></td>
                    <td><?= $unit->isBase() ? '1' : '1 ' . $e($unit->code()) . ' = ' . $unit->factor() . ' ' . $e($units->baseCode()) ?></td>
                    <td><?= $e($unit->barcode() ?? '-') ?></td>
                    <td><?= $unit->isBase() ? \App\Support\Money::rupiah($product->purchasePrice()) : $priceText($unit->purchasePrice(), $unit->unitPurchasePrice($product)) ?></td>
                    <td><?= $unit->isBase() ? \App\Support\Money::rupiah($product->sellingPrice()) : $priceText($unit->sellingPrice(), $unit->unitSellingPrice($product)) ?></td>
                    <td><?= $e(implode(', ', array_filter([$unit->isPurchaseDefault() ? 'Purchase' : '', $unit->isSalesDefault() ? 'Sales' : '']))) ?: '-' ?></td>
                    <td><span class="status-badge <?= $unit->isActive() ? 'status-normal' : 'status-inactive' ?>"><?= $unit->isActive() ? 'Active' : 'Inactive' ?></span><?= ($unitUsage[$unit->id()] ?? false) ? ' <small class="field-hint">used on documents</small>' : '' ?></td>
                    <?php if ($canManageUnits): ?>
                    <td>
                        <?php if ($unit->id() > 0): $used = (bool) ($unitUsage[$unit->id()] ?? false); $fixed = $unit->isBase() || $used; ?>
                        <form method="post" action="/products/units/update" class="unit-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="id" value="<?= $product->id() ?>">
                            <input type="hidden" name="unit_id" value="<?= $unit->id() ?>">
                            <?php if (!$fixed): ?>
                                <label class="field"><span class="field-label">Code</span><input name="unit_code" maxlength="30" required value="<?= $e($value('unit_code', $unit->code(), $unit->id())) ?>"></label>
                                <label class="field"><span class="field-label">Factor</span><input name="factor_to_base" type="number" min="2" max="<?= \App\Service\ProductUnitService::MAX_FACTOR ?>" required value="<?= $e($value('factor_to_base', (string) $unit->factor(), $unit->id())) ?>"></label>
                            <?php endif; ?>
                            <label class="field"><span class="field-label">Barcode</span><input name="barcode" maxlength="64" value="<?= $e($value('barcode', (string) $unit->barcode(), $unit->id())) ?>"></label>
                            <?php if (!$unit->isBase()): ?>
                                <label class="field"><span class="field-label">Purchase price per unit (empty = base × factor)</span><input name="purchase_price" type="number" min="0" step="0.01" value="<?= $e($value('purchase_price', $unit->purchasePrice() === null ? '' : number_format($unit->purchasePrice(), 2, '.', ''), $unit->id())) ?>"></label>
                                <label class="field"><span class="field-label">Selling price per unit (empty = base × factor)</span><input name="selling_price" type="number" min="0" step="0.01" value="<?= $e($value('selling_price', $unit->sellingPrice() === null ? '' : number_format($unit->sellingPrice(), 2, '.', ''), $unit->id())) ?>"></label>
                            <?php endif; ?>
                            <label><input type="checkbox" name="is_purchase_default" value="1"<?= $unit->isPurchaseDefault() ? ' checked' : '' ?>> Purchase default</label>
                            <label><input type="checkbox" name="is_sales_default" value="1"<?= $unit->isSalesDefault() ? ' checked' : '' ?>> Sales default</label>
                            <?php if (!$unit->isBase()): ?><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1"<?= $unit->isActive() ? ' checked' : '' ?>> Active</label><?php else: ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
                            <button type="submit">Save Unit</button>
                        </form>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php if ($canManageUnits): ?>
    <form method="post" action="/products/units" class="form unit-add-form" data-unit-add>
        <h3>Add Unit</h3>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="id" value="<?= $product->id() ?>">
        <label class="field"><span class="field-label">Unit code</span><input name="unit_code" maxlength="30" required placeholder="karton" value="<?= $e($value('unit_code', '', 0)) ?>"></label>
        <label class="field"><span class="field-label">Factor (<?= $e($units->baseCode()) ?> per unit)</span><input name="factor_to_base" type="number" min="2" max="<?= \App\Service\ProductUnitService::MAX_FACTOR ?>" required value="<?= $e($value('factor_to_base', '', 0)) ?>"></label>
        <label class="field"><span class="field-label">Barcode (optional)</span><input name="barcode" maxlength="64" value="<?= $e($value('barcode', '', 0)) ?>"></label>
        <label class="field"><span class="field-label">Purchase price per unit (empty = base × factor)</span><input name="purchase_price" type="number" min="0" step="0.01" value="<?= $e($value('purchase_price', '', 0)) ?>"></label>
        <label class="field"><span class="field-label">Selling price per unit (empty = base × factor)</span><input name="selling_price" type="number" min="0" step="0.01" value="<?= $e($value('selling_price', '', 0)) ?>"></label>
        <label><input type="checkbox" name="is_purchase_default" value="1"> Purchase default</label>
        <label><input type="checkbox" name="is_sales_default" value="1"> Sales default</label>
        <div class="form-actions"><button type="submit">Add Unit</button></div>
    </form>
    <?php endif; ?>
</section>
