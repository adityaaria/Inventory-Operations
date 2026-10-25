<?php
// Quotations (D-19, ADR-025): Sales see their own, Admin all. Expired is computed from valid_until.
$appTitle = 'Sales'; $title = 'Quotations'; $subtitle = 'Offers to customers before the order. An accepted quotation converts to one sales order draft at the quoted prices while it is valid.';
$toolbar = '<a class="button" href="/quotations/create">Create Quotation</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$q = is_string($query['q'] ?? null) ? $query['q'] : ''; $statusFilter = is_string($query['status'] ?? null) ? $query['status'] : '';
?>
<form method="get" action="/quotations" class="filters"><label>Search <input name="q" maxlength="80" value="<?= $escape($q) ?>" placeholder="QT number or customer"></label>
<label>Status <select name="status"><option value="">All</option><?php foreach (\App\Support\QuotationStatus::FILTERS as $option): ?><option value="<?= $escape($option) ?>"<?= $statusFilter === $option ? ' selected' : '' ?>><?= $escape($option) ?></option><?php endforeach; ?></select></label>
<button type="submit">Filter</button><a class="button" href="/quotations">Reset</a></form>
<section class="table-scroll" aria-label="Quotations"><table class="data-table" data-quotations><thead><tr><th>Number</th><th>Customer</th><th>Date</th><th>Valid until</th><th>Status</th><th>Total (excl. PPN)</th><th>Sales order</th><th>Owner</th></tr></thead><tbody>
<?php foreach ($result->items() as $row): $effective = \App\Support\QuotationStatus::effective($row, $today); ?>
<tr><td><a href="/quotations/show?id=<?= (int) $row['id'] ?>"><?= $escape($row['quotation_number']) ?></a></td><td><?= $escape($row['customer_name']) ?></td><td><?= $escape($row['quote_date']) ?></td><td><?= $escape($row['valid_until']) ?></td>
<td><span class="status-badge <?= \App\Support\QuotationStatus::tone($effective) ?>"><?= $escape($effective) ?></span></td><td><?= \App\Support\Money::rupiah((float) $row['total']) ?></td>
<td><?= $row['sales_order_id'] !== null ? '<a href="/sales-orders/show?id=' . (int) $row['sales_order_id'] . '">' . $escape($row['sales_order_number']) . '</a>' : '—' ?></td><td><?= $escape($row['created_by_name']) ?></td></tr>
<?php endforeach; ?>
<?php if ($result->total() === 0): ?><tr><td colspan="8">No quotation matches.</td></tr><?php endif; ?>
</tbody></table></section>
<?php $paginationPath = '/quotations'; $paginationLabel = 'Quotations'; $paginationQuery = ['q' => $q, 'status' => $statusFilter]; require dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
