<?php
// $priceField/$priceLabel: purchase_price/Purchase Price or selling_price/Selling Price.
// Negotiated prices (D-13, ADR-019): the price is editable and prefilled with the product master price (`data-price`).
// A different price needs a reason; the server validates both and always snapshots the master price itself.
// Units (D-16, ADR-022): each line picks a unit of the product; quantity and price are per that unit and the server
// stores the base quantity. Product options carry their units (`data-units`) and the default unit for this form.
// $prefix: '' for the primary line, 'items[n][' for supplementary lines, null for the clone template (no name, never parsed as a field).
$itemName = static function (string $field) use ($prefix): string {
    if ($prefix === null) {
        return '';
    }
    $name = $prefix === '' ? $field : $prefix . $field . ']';
    return ' name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
};
$isSales = $priceField === 'selling_price';
$unitSets = $unitSets ?? [];
$unitSetFor = static fn ($product): \App\Support\UnitSet => $unitSets[$product->id()] ?? \App\Support\UnitSet::forProduct($product);
$unitPrice = static fn ($product, \App\Entity\ProductUnit $unit): string => number_format($isSales ? $unit->unitSellingPrice($product) : $unit->unitPurchasePrice($product), 2, '.', '');
$masterPrice = static fn ($product): string => number_format($isSales ? $product->sellingPrice() : $product->purchasePrice(), 2, '.', '');
// The selected product, or the first option, which the browser selects when nothing matches.
$selectedId = $itemState->value('product_id', '');
$selectedProduct = null;
foreach ($products as $product) {
    if ($selectedProduct === null || (string) $product->id() === $selectedId) {
        $selectedProduct = $product;
    }
    if ((string) $product->id() === $selectedId) {
        break;
    }
}
$selectedUnits = $selectedProduct === null ? null : $unitSetFor($selectedProduct);
$defaultUnit = $selectedUnits === null ? null : ($isSales ? $selectedUnits->salesDefault() : $selectedUnits->purchaseDefault());
$typedUnit = trim($itemState->value('unit_code', ''));
$selectedUnit = $defaultUnit;
if ($selectedUnits !== null && $typedUnit !== '') {
    $candidate = $selectedUnits->find($typedUnit);
    $selectedUnit = $candidate !== null && $candidate->isActive() ? $candidate : $defaultUnit;
}
$selectedPrice = $selectedProduct === null || $selectedUnit === null ? '' : $unitPrice($selectedProduct, $selectedUnit);
// A re-rendered (422) line keeps the typed price and reason; otherwise (new line, replenishment prefill) the master price.
$typedPrice = trim($itemState->value($priceField, ''));
$priceValue = $typedPrice !== '' ? $typedPrice : $selectedPrice;
$reasonValue = $itemState->value('price_reason', '');
$priceDiffers = $selectedPrice !== '' && is_numeric($priceValue) && (int) round((float) $priceValue * 100) !== (int) round((float) $selectedPrice * 100);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<fieldset data-order-item class="stock-proposal-item"><legend>Product item</legend>
    <label class="field"><span class="field-label">Product</span>
        <select data-order-input="product_id"<?= $itemName('product_id') ?> required>
            <?php foreach ($products as $product): $set = $unitSetFor($product); ?>
                <option value="<?= $product->id() ?>" data-price="<?= $masterPrice($product) ?>" <?= $itemState->selected('product_id', $product->id(), false) ?> data-base-unit="<?= $escape($set->baseCode()) ?>" data-default-unit="<?= $escape(($isSales ? $set->salesDefault() : $set->purchaseDefault())->code()) ?>" data-units="<?= $escape((string) json_encode($set->describe($product), JSON_UNESCAPED_UNICODE)) ?>"><?= htmlspecialchars($product->sku() . ' - ' . $product->name(), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="order-quantity">
        <label class="field"><span class="field-label">Quantity</span><input data-order-input="quantity"<?= $itemName('quantity') ?> type="number" min="1" required value="<?= $escape($itemState->value('quantity', '')) ?>"></label>
        <label class="field"><span class="field-label">Unit</span>
            <select data-order-input="unit_code" data-order-unit<?= $itemName('unit_code') ?>>
                <?php if ($selectedProduct !== null && $selectedUnits !== null): foreach ($selectedUnits->active() as $unit): ?>
                    <option value="<?= $escape($unit->code()) ?>" data-factor="<?= $unit->factor() ?>" data-price="<?= $unitPrice($selectedProduct, $unit) ?>"<?= $selectedUnit !== null && $unit->code() === $selectedUnit->code() ? ' selected' : '' ?>><?= $escape($unit->code() . ($unit->isBase() ? '' : ' (' . $unit->factor() . ' ' . $selectedUnits->baseCode() . ')')) ?></option>
                <?php endforeach; endif; ?>
            </select>
        </label>
        <p class="field-hint" data-order-base-hint aria-live="polite"></p>
    </div>
    <div class="order-price">
        <label class="field"><span class="field-label"><?= $escape($priceLabel) ?> (Rp per unit)</span><input data-order-input="<?= $escape($priceField) ?>" data-order-price<?= $itemName($priceField) ?> type="number" min="0" step="0.01" inputmode="decimal" value="<?= $escape($priceValue) ?>"></label>
        <?php if ($isSales && !empty($priceResolution)): ?>
        <?php // D-19 / ADR-025: price-resolution.js replaces the list price with the customer's price list tier (server re-resolves on save). ?>
        <p class="field-hint" data-order-price-hint aria-live="polite">List price: <span data-order-master><?= $selectedPrice === '' ? '-' : \App\Support\Money::rupiah((float) $selectedPrice) ?></span><span data-order-master-unit><?= $selectedUnit === null ? '' : ' per ' . $escape($selectedUnit->code()) ?></span> · <span data-order-price-source>Master price</span><span data-order-next-tier></span><span data-order-price-diff><?= $priceDiffers ? ' · differs from list price' : '' ?></span></p>
        <?php else: ?>
        <p class="field-hint" data-order-price-hint aria-live="polite">Master price: <span data-order-master><?= $selectedPrice === '' ? '-' : \App\Support\Money::rupiah((float) $selectedPrice) ?></span><span data-order-master-unit><?= $selectedUnit === null ? '' : ' per ' . $escape($selectedUnit->code()) ?></span><span data-order-price-diff><?= $priceDiffers ? ' · differs from master' : '' ?></span></p>
        <?php endif; ?>
        <button type="button" class="button button-quiet" data-order-price-reset><?= $isSales && !empty($priceResolution) ? 'Use list price' : 'Use master price' ?></button>
    </div>
    <label class="field" data-order-reason-field<?= $priceDiffers || trim($reasonValue) !== '' ? '' : ' hidden' ?>><span class="field-label">Reason for price change</span><input data-order-input="price_reason" data-order-reason<?= $itemName('price_reason') ?> type="text" maxlength="255" autocomplete="off"<?= $priceDiffers ? ' required minlength="3"' : '' ?> value="<?= $escape($reasonValue) ?>"><span class="field-hint">Required when the price differs from the <?= $isSales && !empty($priceResolution) ? 'list' : 'master' ?> price (3–255 characters).</span></label>
    <output data-order-item-message></output>
    <button type="button" class="button button-quiet" data-order-remove <?= $prefix === '' ? 'disabled' : '' ?>>Remove item</button>
</fieldset>
