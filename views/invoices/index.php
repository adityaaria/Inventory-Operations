<?php
// Invoices (D-19, ADR-025): Admin all, Sales invoices of their own sales orders (read-only). Status is computed.
$appTitle = 'Billing'; $title = 'Invoices'; $subtitle = 'One invoice per delivery note; prices exclude PPN and the PPN settings are kept on each invoice.';
$toolbar = ($isAdmin ? '<a class="button" href="/delivery-notes?invoiced=no">Delivery notes to invoice</a><a href="/reports/receivables">Receivables Aging</a><a href="/reports/sales-by-customer">Sales by Customer</a>' : '');
require dirname(__DIR__) . '/inventory-operations/start.php';
$q = is_string($query['q'] ?? null) ? $query['q'] : ''; $state = is_string($query['state'] ?? null) ? $query['state'] : '';
?>
<form method="get" action="/invoices" class="filters"><label>Search <input name="q" maxlength="80" value="<?= $escape($q) ?>" placeholder="INV, SO, DO or customer"></label>
<label>Status <select name="state"><option value="">All</option><?php foreach (\App\Support\InvoiceStatus::FILTERS as $option): ?><option value="<?= $escape($option) ?>"<?= $state === $option ? ' selected' : '' ?>><?= $escape($option) ?></option><?php endforeach; ?></select></label>
<button type="submit">Filter</button><a class="button" href="/invoices">Reset</a></form>
<section class="table-scroll" aria-label="Invoices"><table class="data-table" data-invoices><thead><tr><th>Number</th><th>Customer</th><th>Date</th><th>Due</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Delivery note</th></tr></thead><tbody>
<?php foreach ($result->items() as $row): $rowState = \App\Support\InvoiceStatus::of($row, $today); ?>
<tr><td><a href="/invoices/show?id=<?= (int) $row['id'] ?>"><?= $escape($row['invoice_number']) ?></a></td><td><?= $escape($row['customer_name']) ?></td><td><?= $escape($row['invoice_date']) ?></td><td><?= $escape($row['due_date']) ?></td>
<td><?= \App\Support\Money::rupiah((float) $row['total_amount']) ?></td><td><?= \App\Support\Money::rupiah((float) $row['paid_amount']) ?></td><td><?= \App\Support\Money::rupiah($rowState['outstanding_cents'] / 100) ?></td>
<td><span class="status-badge <?= \App\Support\InvoiceStatus::tone($rowState['status'], $rowState['overdue']) ?>"><?= $escape($rowState['status']) ?></span><?= $rowState['overdue'] ? ' <span class="status-badge status-danger">Overdue ' . (int) $rowState['days_overdue'] . ' d</span>' : '' ?></td>
<td><a href="/delivery-notes/show?id=<?= (int) $row['delivery_note_id'] ?>"><?= $escape($row['delivery_number']) ?></a></td></tr>
<?php endforeach; ?>
<?php if ($result->total() === 0): ?><tr><td colspan="9">No invoice matches.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath = '/invoices'; $paginationLabel = 'Invoices'; $paginationQuery = ['q' => $q, 'state' => $state]; require dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
