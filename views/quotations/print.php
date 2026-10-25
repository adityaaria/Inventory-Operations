<?php
// Printable quotation (D-19, ADR-025).
$printTitle = 'Quotation ' . $quotation['quotation_number']; $printHeading = 'QUOTATION / PENAWARAN HARGA'; $printNumber = $quotation['quotation_number'];
$printStatus = $quotation['effective_status']; $backHref = '/quotations/show?id=' . (int) $quotation['id'];
require dirname(__DIR__) . '/print/start.php';
$total = 0;
?>
<section class="print-parties">
<div><h2>To</h2><p><strong><?= $escape($quotation['customer_name']) ?></strong></p><p><?= $escape($customer?->address() ?? '') ?></p><p><?= $escape($customer?->phone() ?? '') ?></p></div>
<div><h2>Date</h2><p><?= $escape($quotation['quote_date']) ?></p><h2>Valid until</h2><p data-valid-until><?= $escape($quotation['valid_until']) ?></p></div>
<div><h2>Ship from</h2><p><?= $escape($quotation['warehouse_name']) ?></p><h2>Sales</h2><p><?= $escape($quotation['created_by_name']) ?></p></div>
</section>
<div class="print-scroll"><table class="print-table"><thead><tr><th>#</th><th>Product</th><th class="number">Quantity</th><th class="number">Unit price</th><th class="number">Amount</th></tr></thead><tbody>
<?php foreach ($quotation['lines'] as $index => $line): $amount = $line['unit_quantity'] * \App\Support\TaxSettings::cents($line['unit_price']); $total += $amount; ?>
<tr><td><?= $index + 1 ?></td><td><?= $escape($line['sku']) ?><br><?= $escape($line['product_name']) ?></td><td class="number"><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) ?></td><td class="number"><?= \App\Support\Money::rupiah((float) $line['unit_price']) ?></td><td class="number"><?= \App\Support\Money::rupiah($amount / 100) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<table class="print-totals"><tr class="grand"><th>Total (excl. PPN)</th><td><?= \App\Support\Money::rupiah($total / 100) ?></td></tr></table>
<p class="print-note">Prices exclude PPN; PPN is charged on the invoice. This offer is valid until <?= $escape($quotation['valid_until']) ?>.<?= $quotation['notes'] !== '' ? ' ' . $escape($quotation['notes']) : '' ?></p>
<section class="print-signatures"><div>Sales</div><div></div><div>Customer approval</div></section>
<?php require dirname(__DIR__) . '/print/end.php'; ?>
