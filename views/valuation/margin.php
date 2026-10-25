<?php
// Gross margin (D-20, ADR-026), Admin only: invoice net revenue (excl. PPN, ADR-025) − COGS of the shipped lots, per
// customer, product or invoice line; void invoices excluded; shipments without a live invoice listed separately.
$current = 'margin';
require __DIR__ . '/nav.php';
$appTitle = 'Reports'; $title = 'Gross Margin';
$subtitle = 'Invoices dated ' . $report['from'] . ' to ' . $report['to'] . ': net revenue (excl. PPN) − cost of the lots shipped (FIFO per batch). Void invoices excluded.';
$csvQuery = http_build_query(['from' => $report['from'], 'to' => $report['to'], 'customer_id' => $report['filters']['customer_id'] ?: '', 'q' => $report['filters']['q'], 'group' => $report['group']]);
$toolbar = '<a class="button" href="/reports/gross-margin.csv?' . htmlspecialchars($csvQuery, ENT_QUOTES, 'UTF-8') . '" data-export="server">Export CSV</a>' . $valuationNav;
require dirname(__DIR__) . '/inventory-operations/start.php';
$percent = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1, ',', '.') . ' %';
$t = $report['totals'];
?>
<section class="metric-grid" aria-label="Margin summary">
<article class="metric-card" data-margin-metric="revenue"><span>Net revenue</span><strong><?= $rupiahCents($t['revenue_cents']) ?></strong><small class="field-hint"><?= (int) $t['lines'] ?> invoice lines</small></article>
<article class="metric-card" data-margin-metric="cogs"><span>COGS (lot cost)</span><strong><?= $rupiahCents($t['cogs_cents']) ?></strong></article>
<article class="metric-card" data-margin-metric="margin"><span>Gross margin</span><strong data-margin-total="<?= (int) $t['margin_cents'] ?>"><?= $rupiahCents($t['margin_cents']) ?></strong><small class="field-hint"><?= $percent($t['margin_percent']) ?> of net revenue</small></article>
<article class="metric-card" data-margin-metric="not-invoiced"><span>Shipped not invoiced (COGS)</span><strong><?= $rupiahCents($report['not_invoiced_totals']['cogs_cents']) ?></strong><small class="field-hint"><?= count($report['not_invoiced']) ?> delivery-note lines</small></article>
</section>
<?php if ($t['unvalued_revenue_cents'] > 0): ?><p class="alert alert-warning" data-unvalued-revenue><?= $rupiahCents($t['unvalued_revenue_cents']) ?> of revenue was shipped before the valuation start; it has no cost and is excluded from the margin.</p><?php endif; ?>
<form method="get" action="/reports/gross-margin" class="filters">
<label>From <input type="date" name="from" value="<?= $escape($report['from']) ?>"></label><label>To <input type="date" name="to" value="<?= $escape($report['to']) ?>"></label>
<label>Customer <select name="customer_id"><option value="">All customers</option><?php foreach ($customers as $customer): ?><option value="<?= $customer->id() ?>" <?= $report['filters']['customer_id'] === $customer->id() ? 'selected' : '' ?>><?= $escape($customer->name()) ?></option><?php endforeach; ?></select></label>
<label>Product <input name="q" maxlength="120" value="<?= $escape($report['filters']['q']) ?>" placeholder="SKU or name"></label>
<label>Show <select name="group"><?php foreach (\App\Service\InventoryValuationService::MARGIN_GROUPS as $value => $label): ?><option value="<?= $value ?>" <?= $report['group'] === $value ? 'selected' : '' ?>>By <?= $escape(strtolower($label)) ?></option><?php endforeach; ?></select></label>
<button type="submit">Apply</button>
</form>
<?php if ($report['group'] === 'line'): ?>
<section class="table-scroll" aria-label="Margin per invoice line"><table class="data-table" data-margin-lines="<?= count($report['lines']) ?>"><thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Product</th><th>Quantity</th><th>Net revenue</th><th>COGS</th><th>Margin</th><th>%</th></tr></thead><tbody>
<?php foreach ($report['lines'] as $line): ?><tr><td><?= $escape($line['invoice_date']) ?></td><td><a href="/invoices/show?id=<?= (int) $line['invoice_id'] ?>"><?= $escape($line['invoice_number']) ?></a></td><td><?= $escape($line['customer_name']) ?></td><td><?= $escape($line['sku'] . ' — ' . $line['product_name']) ?></td><td><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) ?></td><td><?= $rupiahCents($line['revenue_cents']) ?></td>
<td><?= $line['valued'] ? $rupiahCents($line['cogs_cents']) : 'before valuation start' ?></td><td><?= $line['valued'] ? $rupiahCents($line['margin_cents']) : '—' ?></td><td><?= $percent($line['margin_percent']) ?></td></tr><?php endforeach; ?>
<?php if ($report['lines'] === []): ?><tr><td colspan="9">No invoice in this range.</td></tr><?php endif; ?>
</tbody></table></section>
<?php else: ?>
<section class="table-scroll" aria-label="Margin by <?= $escape($report['group']) ?>"><table class="data-table" data-margin-groups="<?= count($report['groups']) ?>"><thead><tr><th><?= $escape(\App\Service\InventoryValuationService::MARGIN_GROUPS[$report['group']]) ?></th><th>Lines</th><th>Net revenue</th><th>COGS</th><th>Margin</th><th>%</th></tr></thead><tbody>
<?php foreach ($report['groups'] as $group): ?><tr><td><?= $escape($group['label']) ?></td><td><?= (int) $group['lines'] ?></td><td><?= $rupiahCents($group['revenue_cents']) ?></td><td><?= $rupiahCents($group['cogs_cents']) ?></td><td><?= $rupiahCents($group['margin_cents']) ?></td><td><?= $percent($group['margin_percent']) ?></td></tr><?php endforeach; ?>
<?php if ($report['groups'] === []): ?><tr><td colspan="6">No invoice in this range.</td></tr><?php endif; ?>
<tr><th>Total</th><th><?= (int) $t['lines'] ?></th><th><?= $rupiahCents($t['revenue_cents']) ?></th><th><?= $rupiahCents($t['cogs_cents']) ?></th><th><?= $rupiahCents($t['margin_cents']) ?></th><th><?= $percent($t['margin_percent']) ?></th></tr>
</tbody></table></section>
<?php endif; ?>
<section class="card" aria-labelledby="not-invoiced-title"><div class="card-body"><h2 id="not-invoiced-title">Shipped not invoiced</h2>
<p class="field-hint">Delivery notes shipped in the period without a live invoice: their COGS has left stock but no revenue is recognised yet. The SO value is shown for reference only.</p>
<?php if ($report['not_invoiced'] === []): ?><p data-not-invoiced="0">Every shipment of this period is invoiced.</p><?php else: ?>
<section class="table-scroll"><table class="data-table" data-not-invoiced="<?= count($report['not_invoiced']) ?>"><thead><tr><th>Shipped</th><th>Delivery note</th><th>Customer</th><th>Product</th><th>Quantity</th><th>SO value</th><th>COGS</th></tr></thead><tbody>
<?php foreach ($report['not_invoiced'] as $line): ?><tr><td><?= $escape(\App\Service\InventoryValuationService::label($line['shipped_at'])) ?></td><td><a href="/delivery-notes/show?id=<?= (int) $line['delivery_note_id'] ?>"><?= $escape($line['delivery_number']) ?></a></td><td><?= $escape($line['customer_name']) ?></td><td><?= $escape($line['sku'] . ' — ' . $line['product_name']) ?></td><td><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) ?></td><td><?= $rupiahCents($line['revenue_cents']) ?></td><td><?= $line['valued'] ? $rupiahCents($line['cogs_cents']) : 'before valuation start' ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
</div></section>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
