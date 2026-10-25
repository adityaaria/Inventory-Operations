<?php
// Invoice detail (D-19, ADR-025): totals from the snapshot, payments, void (Admin only, no payments).
$state = $invoice['state'];
$appTitle = 'Invoice'; $title = $invoice['invoice_number']; $subtitle = 'PPN ' . (int) $invoice['vat_rate_percent'] . '% of DPP ' . (int) $invoice['vat_dpp_numerator'] . '/' . (int) $invoice['vat_dpp_denominator'] . ' of the net amount (settings at invoicing).';
$toolbar = '<a class="button" href="/invoices/print?id=' . (int) $invoice['id'] . '">Print</a><a href="/invoices">Invoices</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$formState = new \App\Support\FormState($old ?? []);
?>
<dl class="detail-summary">
<div><dt>Status</dt><dd><span class="status-badge <?= \App\Support\InvoiceStatus::tone($state['status'], $state['overdue']) ?>" data-invoice-status><?= $escape($state['status']) ?></span><?= $state['overdue'] ? ' <span class="status-badge status-danger" data-overdue>Overdue ' . (int) $state['days_overdue'] . ' days</span>' : '' ?></dd></div>
<div><dt>Customer</dt><dd><?= $escape($invoice['customer_name']) ?></dd></div>
<div><dt>Invoice date / due</dt><dd><?= $escape($invoice['invoice_date']) ?> / <?= $escape($invoice['due_date']) ?></dd></div>
<div><dt>Sales order</dt><dd><a href="/sales-orders/show?id=<?= (int) $invoice['sales_order_id'] ?>"><?= $escape($invoice['order_number']) ?></a></dd></div>
<div><dt>Delivery note</dt><dd><a href="/delivery-notes/show?id=<?= (int) $invoice['delivery_note_id'] ?>"><?= $escape($invoice['delivery_number']) ?></a></dd></div>
<div><dt>Outstanding</dt><dd data-outstanding><?= \App\Support\Money::rupiah($state['outstanding_cents'] / 100) ?></dd></div>
</dl>
<?php if ($invoice['voided_at'] !== null): ?><p class="alert alert-warning" data-void-reason>Void since <?= $escape($invoice['voided_at']) ?>: <?= $escape($invoice['void_reason']) ?>. Stock and the delivery note are unchanged; the delivery note can be invoiced again.</p><?php endif; ?>
<section class="table-scroll" aria-label="Invoice lines"><table class="detail-table" data-invoice-lines><thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Amount</th></tr></thead><tbody>
<?php foreach ($invoice['lines'] as $line): ?><tr><td><?= $escape($line['sku'] . ' — ' . $line['product_name']) ?></td><td><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) ?></td><td><?= \App\Support\Money::rupiah((float) $line['unit_price']) ?></td><td><?= \App\Support\Money::rupiah((float) $line['amount']) ?></td></tr><?php endforeach; ?>
<tr><th colspan="3">Subtotal (net, excl. PPN)</th><td data-subtotal><?= \App\Support\Money::rupiah((float) $invoice['subtotal']) ?></td></tr>
<tr><th colspan="3">DPP (<?= (int) $invoice['vat_dpp_numerator'] ?>/<?= (int) $invoice['vat_dpp_denominator'] ?>)</th><td data-dpp><?= \App\Support\Money::rupiah((float) $invoice['dpp_amount']) ?></td></tr>
<tr><th colspan="3">PPN <?= (int) $invoice['vat_rate_percent'] ?>%</th><td data-ppn><?= \App\Support\Money::rupiah((float) $invoice['vat_amount']) ?></td></tr>
<tr><th colspan="3">Total</th><td data-total><?= \App\Support\Money::rupiah((float) $invoice['total_amount']) ?></td></tr>
</tbody></table></section>
<?php if (($costing ?? null) !== null): $marginMoney = static fn (?int $cents): string => $cents === null ? '—' : \App\Support\Money::rupiah($cents / 100); ?>
<section class="card" aria-labelledby="invoice-costing-title" data-costing><div class="card-body"><h2 id="invoice-costing-title">Cost of goods and margin (Admin only)</h2>
<p class="field-hint">COGS = the delivery note's lots × their unit cost (FIFO per batch); margin = net amount (excl. PPN) − COGS.</p>
<section class="table-scroll"><table class="data-table" data-invoice-costing><thead><tr><th>Product</th><th>Net amount</th><th>COGS</th><th>Margin</th><th>%</th></tr></thead><tbody>
<?php foreach ($invoice['lines'] as $line): $lineCost = $costing['lines'][(int) $line['delivery_note_line_id']] ?? null; ?><tr><td><?= $escape($line['sku'] . ' — ' . $line['product_name']) ?></td><td><?= \App\Support\Money::rupiah((float) $line['amount']) ?></td>
<td><?= $lineCost !== null && $lineCost['valued'] ? $marginMoney((int) $lineCost['cogs_cents']) : 'before valuation start' ?></td><td><?= $marginMoney($lineCost['margin_cents'] ?? null) ?></td><td><?= ($lineCost['margin_percent'] ?? null) === null ? '—' : number_format((float) $lineCost['margin_percent'], 1, ',', '.') . ' %' ?></td></tr><?php endforeach; ?>
<tr><th>Total</th><th><?= $marginMoney($costing['totals']['revenue_cents']) ?></th><th data-invoice-cogs="<?= (int) $costing['totals']['cogs_cents'] ?>"><?= $marginMoney($costing['totals']['cogs_cents']) ?></th><th data-invoice-margin="<?= (int) $costing['totals']['margin_cents'] ?>"><?= $marginMoney($costing['totals']['margin_cents']) ?></th><th><?= $costing['totals']['margin_percent'] === null ? '—' : number_format((float) $costing['totals']['margin_percent'], 1, ',', '.') . ' %' ?></th></tr>
</tbody></table></section></div></section>
<?php endif; ?>
<section class="card" aria-labelledby="payments-title"><div class="card-body"><h2 id="payments-title">Payments</h2>
<?php if ($invoice['payments'] === []): ?><p>No payment recorded.</p><?php else: ?>
<section class="table-scroll" aria-label="Payments"><table class="data-table" data-payments><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th></tr></thead><tbody>
<?php foreach ($invoice['payments'] as $payment): ?><tr><td><?= $escape($payment['paid_on']) ?></td><td><?= \App\Support\Money::rupiah((float) $payment['amount']) ?></td><td><?= $escape($payment['method']) ?></td><td><?= $escape($payment['reference']) ?></td><td><?= $escape($payment['recorded_by_name']) ?></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
<?php if ($isAdmin && $invoice['voided_at'] === null && $state['outstanding_cents'] > 0): ?>
<form method="post" action="/invoices/payments" class="form" data-payment-form>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
<label class="field"><span class="field-label">Payment date</span><input name="paid_on" type="date" required min="<?= $escape($invoice['invoice_date']) ?>" max="<?= $escape($today) ?>" value="<?= $escape($formState->value('paid_on', $today)) ?>"></label>
<label class="field"><span class="field-label">Amount (Rp)</span><input name="amount" type="number" min="0.01" step="0.01" max="<?= $escape(\App\Support\TaxSettings::decimal($state['outstanding_cents'])) ?>" required value="<?= $escape($formState->value('amount', \App\Support\TaxSettings::decimal($state['outstanding_cents']))) ?>"><span class="field-hint">At most the outstanding amount; overpayment is refused.</span></label>
<label class="field"><span class="field-label">Method</span><select name="method"><?php foreach ($methods as $method): ?><option value="<?= $escape($method) ?>"<?= $formState->value('method', 'Transfer') === $method ? ' selected' : '' ?>><?= $escape($method) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Reference</span><input name="reference" maxlength="100" value="<?= $escape($formState->value('reference', '')) ?>" placeholder="Bank transfer number, giro number"></label>
<div class="form-actions"><button type="submit">Record Payment</button></div>
</form>
<?php endif; ?>
</div></section>
<?php if ($isAdmin && $invoice['voided_at'] === null && $invoice['payments'] === []): ?>
<?php $dialog = ['id' => 'void-invoice', 'title' => 'Void ' . $invoice['invoice_number'], 'intro' => 'A void invoice stays on record with its reason; stock and the delivery note are not changed, and the delivery note can be invoiced again.', 'action' => '/invoices/void', 'hidden' => ['id' => $invoice['id']], 'label' => 'Reason to void', 'submit' => 'Void Invoice'] + ($voidDialog ?? []); require dirname(__DIR__) . '/partials/reason-dialog.php'; ?>
<div class="form-actions"><button type="button" class="button-danger" data-dialog-open="void-invoice">Void Invoice</button></div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
