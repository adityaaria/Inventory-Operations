<?php
// Delivery notes (D-19, ADR-025): one per SO shipment, created with the issue. Sales see notes of their own orders.
$appTitle = 'Shipping'; $title = 'Delivery Notes'; $subtitle = 'Surat jalan of every shipment (DO-YYMM-NNNN), with the lots used. Admin invoices a delivery note once.';
$toolbar = '<a class="button" href="/sales-orders">Sales Orders</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$q = is_string($query['q'] ?? null) ? $query['q'] : ''; $invoiced = is_string($query['invoiced'] ?? null) ? $query['invoiced'] : '';
?>
<form method="get" action="/delivery-notes" class="filters"><label>Search <input name="q" maxlength="80" value="<?= $escape($q) ?>" placeholder="DO, SO number or customer"></label>
<label>Invoice <select name="invoiced"><option value="">All</option><option value="no"<?= $invoiced === 'no' ? ' selected' : '' ?>>Not invoiced</option><option value="yes"<?= $invoiced === 'yes' ? ' selected' : '' ?>>Invoiced</option></select></label>
<button type="submit">Filter</button><a class="button" href="/delivery-notes">Reset</a></form>
<section class="table-scroll" aria-label="Delivery notes"><table class="data-table" data-delivery-notes><thead><tr><th>Number</th><th>Sales order</th><th>Shipment</th><th>Customer</th><th>Shipped</th><th>Units</th><th>Vehicle / driver</th><th>Invoice</th></tr></thead><tbody>
<?php foreach ($result->items() as $row): ?>
<tr><td><a href="/delivery-notes/show?id=<?= (int) $row['id'] ?>"><?= $escape($row['delivery_number']) ?></a><?= $row['origin'] === 'Migration' ? ' <small>(history)</small>' : '' ?></td><td><a href="/sales-orders/show?id=<?= (int) $row['sales_order_id'] ?>"><?= $escape($row['order_number']) ?></a></td><td>#<?= (int) $row['shipment_number'] ?></td>
<td><?= $escape($row['ship_to_name']) ?></td><td><?= $escape($row['shipped_at']) ?></td><td><?= (int) $row['units'] ?></td><td><?= $escape(trim(($row['vehicle'] ?? '') . ' ' . ($row['driver'] ?? '')) ?: '—') ?></td>
<td><?php if ($row['invoice_id'] !== null): ?><a href="/invoices/show?id=<?= (int) $row['invoice_id'] ?>"><?= $escape($row['invoice_number']) ?></a><?php elseif ($canInvoice): ?><form method="post" action="/invoices" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="delivery_note_id" value="<?= (int) $row['id'] ?>"><button type="submit">Create Invoice</button></form><?php else: ?>Not invoiced<?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if ($result->total() === 0): ?><tr><td colspan="8">No delivery note matches.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath = '/delivery-notes'; $paginationLabel = 'Delivery notes'; $paginationQuery = ['q' => $q, 'invoiced' => $invoiced]; require dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
