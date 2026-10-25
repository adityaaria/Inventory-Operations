<?php
// Accounts receivable ageing (D-19, ADR-025), Admin only. Buckets: days since the invoice date.
$appTitle = 'Reports'; $title = 'Receivables Aging'; $subtitle = 'Outstanding amounts of open invoices by customer, aged by days since the invoice date (as of ' . $report['today'] . '). Overdue = past the due date.';
$toolbar = '<a class="button" href="/reports/receivables.csv">Export CSV</a><a href="/reports/sales-by-customer">Sales by Customer</a><a href="/invoices">Invoices</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$buckets = array_keys(\App\Service\InvoiceService::AGE_BUCKETS);
?>
<section class="metric-grid"><article class="metric-card"><span>Outstanding</span><strong data-ar-outstanding><?= \App\Support\Money::rupiah($report['totals']['outstanding'] / 100) ?></strong></article>
<article class="metric-card"><span>Overdue</span><strong data-ar-overdue><?= \App\Support\Money::rupiah($report['totals']['overdue'] / 100) ?></strong></article>
<article class="metric-card"><span>Open invoices</span><strong><?= (int) $report['totals']['invoices'] ?></strong></article></section>
<section class="table-scroll" aria-label="Receivables aging"><table class="data-table" data-ar-aging><thead><tr><th>Customer</th><?php foreach ($buckets as $bucket): ?><th><?= $escape($bucket) ?> days</th><?php endforeach; ?><th>Outstanding</th><th>Overdue</th><th>Open invoices</th></tr></thead><tbody>
<?php foreach ([...$report['rows'], ['customer_name' => 'Total'] + $report['totals']] as $row): ?>
<tr><td><?= $escape($row['customer_name']) ?></td><?php foreach ($buckets as $bucket): ?><td><?= \App\Support\Money::rupiah($row[$bucket] / 100) ?></td><?php endforeach; ?><td><?= \App\Support\Money::rupiah($row['outstanding'] / 100) ?></td><td><?= \App\Support\Money::rupiah($row['overdue'] / 100) ?></td><td><?= (int) $row['invoices'] ?></td></tr>
<?php endforeach; ?>
</tbody></table></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
