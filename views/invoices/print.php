<?php
// Printable invoice with PPN (D-19, ADR-025). The tax figures come from the invoice's snapshot.
$state = $invoice['state'];
$printTitle = 'Invoice ' . $invoice['invoice_number']; $printHeading = 'INVOICE / FAKTUR PENJUALAN'; $printNumber = $invoice['invoice_number'];
$printStatus = $state['status'] . ($state['overdue'] ? ' · overdue' : ''); $backHref = '/invoices/show?id=' . (int) $invoice['id'];
require dirname(__DIR__) . '/print/start.php';
?>
<section class="print-parties">
<div><h2>Bill to</h2><p><strong><?= $escape($invoice['customer_tax_name'] ?? $invoice['customer_name']) ?></strong></p><p><?= $escape($invoice['customer_tax_address'] ?? $invoice['customer_address']) ?></p>
<?php if ($invoice['customer_npwp'] !== null): ?><p data-npwp>NPWP <?= $escape($invoice['customer_npwp']) ?></p><?php elseif ($invoice['customer_nik'] !== null): ?><p>NIK <?= $escape($invoice['customer_nik']) ?></p><?php endif; ?></div>
<div><h2>Invoice date</h2><p><?= $escape($invoice['invoice_date']) ?></p><h2>Due date</h2><p><?= $escape($invoice['due_date']) ?></p></div>
<div><h2>Delivery note</h2><p><?= $escape($invoice['delivery_number']) ?></p><h2>Sales order</h2><p><?= $escape($invoice['order_number']) ?></p></div>
</section>
<div class="print-scroll"><table class="print-table"><thead><tr><th>#</th><th>Product</th><th class="number">Quantity</th><th class="number">Unit price</th><th class="number">Amount</th></tr></thead><tbody>
<?php foreach ($invoice['lines'] as $index => $line): ?><tr><td><?= $index + 1 ?></td><td><?= $escape($line['sku']) ?><br><?= $escape($line['product_name']) ?></td><td class="number"><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) ?></td><td class="number"><?= \App\Support\Money::rupiah((float) $line['unit_price']) ?></td><td class="number"><?= \App\Support\Money::rupiah((float) $line['amount']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<table class="print-totals">
<tr><th>Subtotal (excl. PPN)</th><td><?= \App\Support\Money::rupiah((float) $invoice['subtotal']) ?></td></tr>
<tr><th>DPP (<?= (int) $invoice['vat_dpp_numerator'] ?>/<?= (int) $invoice['vat_dpp_denominator'] ?>)</th><td><?= \App\Support\Money::rupiah((float) $invoice['dpp_amount']) ?></td></tr>
<tr><th>PPN <?= (int) $invoice['vat_rate_percent'] ?>%</th><td data-print-ppn><?= \App\Support\Money::rupiah((float) $invoice['vat_amount']) ?></td></tr>
<tr class="grand"><th>Total</th><td><?= \App\Support\Money::rupiah((float) $invoice['total_amount']) ?></td></tr>
<?php if ((float) $invoice['paid_amount'] > 0): ?><tr><th>Paid</th><td><?= \App\Support\Money::rupiah((float) $invoice['paid_amount']) ?></td></tr><tr><th>Outstanding</th><td><?= \App\Support\Money::rupiah($state['outstanding_cents'] / 100) ?></td></tr><?php endif; ?>
</table>
<p class="print-note">DPP and PPN are rounded to whole Rupiah per invoice. <?= $invoice['voided_at'] !== null ? 'VOID: ' . $escape($invoice['void_reason']) . '.' : '' ?></p>
<section class="print-signatures"><div>Finance</div><div></div><div>Customer</div></section>
<?php require dirname(__DIR__) . '/print/end.php'; ?>
