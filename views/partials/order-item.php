<?php
// $priceField/$priceLabel: purchase_price/Purchase Price or selling_price/Selling Price.
// The price is display-only (no name, never submitted): services always price lines from the product master.
// $prefix: '' for the primary line, 'items[n][' for supplementary lines, null for the clone template (no name, never parsed as a field).
$itemName = static function (string $field) use ($prefix): string {
    if ($prefix === null) {
        return '';
    }
    $name = $prefix === '' ? $field : $prefix . $field . ']';
    return ' name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
};
$masterPrice = static fn ($product): string => number_format($priceField === 'selling_price' ? $product->sellingPrice() : $product->purchasePrice(), 2, '.', '');
// Price of the selected product, or of the first option, which the browser selects when nothing matches.
$selectedId = $itemState->value('product_id', '');
$selectedPrice = '';
foreach ($products as $product) {
    if ($selectedPrice === '' || (string) $product->id() === $selectedId) {
        $selectedPrice = $masterPrice($product);
    }
    if ((string) $product->id() === $selectedId) {
        break;
    }
}
?>
<fieldset data-order-item class="stock-proposal-item"><legend>Product item</legend>
    <label class="field"><span class="field-label">Product</span>
        <select data-order-input="product_id"<?= $itemName('product_id') ?> required>
            <?php foreach ($products as $product): ?>
                <option value="<?= $product->id() ?>" data-price="<?= $masterPrice($product) ?>" <?= $itemState->selected('product_id', $product->id(), false) ?>><?= htmlspecialchars($product->sku() . ' - ' . $product->name(), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field"><span class="field-label">Quantity</span><input data-order-input="quantity"<?= $itemName('quantity') ?> type="number" min="1" required value="<?= htmlspecialchars($itemState->value('quantity', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
    <label class="field"><span class="field-label"><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?> (product master)</span><input data-order-price type="text" readonly value="<?= $selectedPrice === '' ? '' : \App\Support\Money::rupiah((float) $selectedPrice) ?>"></label>
    <output data-order-item-message></output>
    <button type="button" class="button button-quiet" data-order-remove <?= $prefix === '' ? 'disabled' : '' ?>>Remove item</button>
</fieldset>
