<?php
// $priceField/$priceLabel: purchase_price/Purchase Price or selling_price/Selling Price.
// $prefix: '' for the primary line, 'items[n][' for supplementary lines, null for the clone template (no name, never parsed as a field).
$itemName = static fn (string $field): string => $prefix === null ? '' : ' name="' . htmlspecialchars($prefix === '' ? $field : $prefix . $field . ']', ENT_QUOTES, 'UTF-8') . '"';
?>
<fieldset data-order-item class="stock-proposal-item"><legend>Product item</legend>
    <label class="field"><span class="field-label">Product</span>
        <select data-order-input="product_id"<?= $itemName('product_id') ?> required>
            <?php foreach ($products as $product): ?>
                <option value="<?= $product->id() ?>" <?= $itemState->selected('product_id', $product->id(), false) ?>><?= htmlspecialchars($product->sku() . ' - ' . $product->name(), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field"><span class="field-label">Quantity</span><input data-order-input="quantity"<?= $itemName('quantity') ?> type="number" min="1" required value="<?= htmlspecialchars($itemState->value('quantity', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
    <label class="field"><span class="field-label"><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?></span><input data-order-input="<?= $priceField ?>"<?= $itemName($priceField) ?> type="number" min="0" step="0.01" required value="<?= htmlspecialchars($itemState->value($priceField, ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
    <p data-order-item-message role="status"></p>
    <button type="button" class="button button-quiet" data-order-remove <?= $prefix === '' ? 'disabled' : '' ?>>Remove item</button>
</fieldset>
