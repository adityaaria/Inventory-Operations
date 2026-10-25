<?php
// Printable delivery note / surat jalan (D-19, ADR-025): line unit and base, lots used (FEFO), vehicle and driver.
$printTitle = 'Delivery note ' . $note['delivery_number']; $printHeading = 'DELIVERY NOTE / SURAT JALAN'; $printNumber = $note['delivery_number'];
$printStatus = 'Shipment #' . $note['shipment_number'] . ' of ' . $note['order_number']; $backHref = '/sales-orders/show?id=' . (int) $note['sales_order_id'];
require dirname(__DIR__) . '/print/start.php';
?>
<section class="print-parties">
<div><h2>Ship to</h2><p><strong data-ship-to><?= $escape($note['ship_to_name']) ?></strong></p><p><?= $escape($note['ship_to_address']) ?></p><p><?= $escape($customer?->phone() ?? '') ?></p></div>
<div><h2>Ship from</h2><p><?= $escape($note['warehouse_name']) ?></p><p><?= $escape($note['warehouse_location']) ?></p></div>
<div><h2>Shipped</h2><p><?= $escape($note['shipped_at']) ?></p><h2>Sales order</h2><p><?= $escape($note['order_number']) ?></p></div>
<div><h2>Vehicle</h2><p data-vehicle><?= $escape($note['vehicle'] ?? '—') ?></p><h2>Driver</h2><p data-driver><?= $escape($note['driver'] ?? '—') ?></p></div>
</section>
<div class="print-scroll"><table class="print-table" data-delivery-lines><thead><tr><th>#</th><th>Product</th><th class="number">Quantity</th><th class="number">Base units</th><th>Lots (expiry)</th></tr></thead><tbody>
<?php foreach ($note['lines'] as $index => $line): ?>
<tr><td><?= $index + 1 ?></td><td><?= $escape($line['sku']) ?><br><?= $escape($line['product_name']) ?></td><td class="number"><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) ?></td><td class="number"><?= (int) $line['quantity'] . ' ' . $escape($line['base_unit']) ?></td>
<td><?php foreach ($line['lots'] as $lot): ?><div data-lot><?= $escape($lot['lot_code'] ?? 'pre-lot stock') ?><?= $lot['expiry_date'] !== null ? ' (exp. ' . $escape($lot['expiry_date']) . ')' : '' ?>: <?= (int) $lot['quantity'] ?></div><?php endforeach; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="print-note">Prepared by <?= $escape($note['prepared_by_name']) ?><?= $note['origin'] === 'Migration' ? ' · created from the stock ledger for a shipment made before delivery notes existed (ship-to = customer master data at migration)' : '' ?>.
<?php if ($canSeeInvoice): ?><span class="no-print"><?php if ($note['invoice_id'] !== null): ?> Invoice: <a href="/invoices/show?id=<?= (int) $note['invoice_id'] ?>"><?= $escape($note['invoice_number']) ?></a>.<?php else: ?> Not invoiced yet.<?php endif; ?></span><?php endif; ?></p>
<?php if ($canInvoice && $note['invoice_id'] === null): ?><form method="post" action="/invoices" class="no-print"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="delivery_note_id" value="<?= (int) $note['id'] ?>"><button type="submit">Create Invoice</button></form><?php endif; ?>
<?php if (($costing ?? null) !== null && $costing['notes'] !== []): require dirname(__DIR__) . '/partials/costing.php'; endif; ?>
<section class="print-signatures"><div>Warehouse</div><div>Driver</div><div>Received by (customer)</div></section>
<?php require dirname(__DIR__) . '/print/end.php'; ?>
