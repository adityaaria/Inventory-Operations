<?php
// Negotiated line price columns (D-13, ADR-019) for PO/SO detail tables. Expects $item (PricedOrderLine) and is only
// included when the order has at least one overridden line, so master-priced orders keep their original table.
/** @var \App\Entity\PricedOrderLine $item */
?>
<?php if ($item->isPriceOverridden()): ?>
    <td><?= \App\Support\Money::rupiah($item->listPrice()) ?></td>
    <td data-price-difference><?= htmlspecialchars(\App\Support\PriceOverrides::percentLabel($item), ENT_QUOTES, 'UTF-8') ?> · <?= \App\Support\PriceOverrides::signedRupiah(\App\Support\PriceOverrides::unitDifference($item)) ?> per unit · <?= \App\Support\PriceOverrides::signedRupiah(\App\Support\PriceOverrides::lineDifference($item)) ?> line</td>
    <td class="price-override-reason"><?= htmlspecialchars((string) $item->priceReason(), ENT_QUOTES, 'UTF-8') ?></td>
<?php else: ?>
    <td><?= \App\Support\Money::rupiah($item->listPrice()) ?></td>
    <td>Master price</td>
    <td>-</td>
<?php endif; ?>
