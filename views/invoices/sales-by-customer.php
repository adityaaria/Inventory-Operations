<?php
// Sales by customer (D-19, ADR-025), Admin only: non-void invoices in the date range.
$appTitle = 'Reports'; $title = 'Sales by Customer'; $subtitle = 'Invoiced net amount (excl. PPN), PPN and total of non-void invoices dated ' . $report['from'] . ' to ' . $report['to'] . '.';
$toolbar = '<a class="button" href="/reports/sales-by-customer.csv?' . htmlspecialchars(http_build_query(['from' => $report['from'], 'to' => $report['to']]), ENT_QUOTES, 'UTF-8') . '">Export CSV</a><a href="/reports/receivables">Receivables Aging</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
?>
<form method="get" action="/reports/sales-by-customer" class="filters"><label>From <input type="date" name="from" value="<?= $escape($report['from']) ?>"></label><label>To <input type="date" name="to" value="<?= $escape($report['to']) ?>"></label><button type="submit">Apply</button></form>
<section class="table-scroll" aria-label="Sales by customer"><table class="data-table" data-sales-by-customer><thead><tr><th>Customer</th><th>Invoices</th><th>Net</th><th>PPN</th><th>Total</th><th>Paid</th></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): ?><tr><td><?= $escape($row['customer_name']) ?></td><td><?= (int) $row['invoices'] ?></td><td><?= \App\Support\Money::rupiah((float) $row['net']) ?></td><td><?= \App\Support\Money::rupiah((float) $row['vat']) ?></td><td><?= \App\Support\Money::rupiah((float) $row['total']) ?></td><td><?= \App\Support\Money::rupiah((float) $row['paid']) ?></td></tr><?php endforeach; ?>
<?php if ($report['rows'] === []): ?><tr><td colspan="6">No invoice in this range.</td></tr><?php endif; ?>
<tr><th>Total</th><th><?= (int) $report['totals']['invoices'] ?></th><th><?= \App\Support\Money::rupiah($report['totals']['net'] / 100) ?></th><th><?= \App\Support\Money::rupiah($report['totals']['vat'] / 100) ?></th><th><?= \App\Support\Money::rupiah($report['totals']['total'] / 100) ?></th><th><?= \App\Support\Money::rupiah($report['totals']['paid'] / 100) ?></th></tr>
</tbody></table></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
