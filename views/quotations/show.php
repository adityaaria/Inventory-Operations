<?php
// Quotation detail (D-19, ADR-025): lifecycle actions for the owner or an Admin, conversion to an SO draft.
$effective = $quotation['effective_status'];
$appTitle = 'Quotation'; $title = $quotation['quotation_number']; $subtitle = 'Prices exclude PPN. Expired is computed from the validity date.';
$toolbar = '<a class="button" href="/quotations/print?id=' . (int) $quotation['id'] . '" data-print-link>Print</a><a href="/quotations">Quotations</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$total = 0;
?>
<dl class="detail-summary">
<div><dt>Status</dt><dd><span class="status-badge <?= \App\Support\QuotationStatus::tone($effective) ?>" data-quotation-status><?= $escape($effective) ?></span><?php if ($quotation['sales_order_id'] !== null): ?> → <a href="/sales-orders/show?id=<?= (int) $quotation['sales_order_id'] ?>" data-converted-order><?= $escape($quotation['sales_order_number']) ?></a><?php endif; ?></dd></div>
<div><dt>Customer</dt><dd><?= $escape($quotation['customer_name']) ?></dd></div>
<div><dt>Source</dt><dd><?= $escape($quotation['warehouse_name']) ?></dd></div>
<div><dt>Date</dt><dd><?= $escape($quotation['quote_date']) ?></dd></div>
<div><dt>Valid until</dt><dd><?= $escape($quotation['valid_until']) ?><?= $quotation['status'] === 'Accepted' && $quotation['valid_until'] < $quotation['today'] && $quotation['sales_order_id'] === null ? ' (validity ended: cannot be converted)' : '' ?></dd></div>
<div><dt>Owner</dt><dd><?= $escape($quotation['created_by_name']) ?></dd></div>
</dl>
<?php if ($quotation['rejection_reason'] !== null): ?><p class="alert">Rejected by the customer: <?= $escape($quotation['rejection_reason']) ?></p><?php endif; ?>
<?php if ($quotation['notes'] !== ''): ?><p class="field-hint">Notes: <?= $escape($quotation['notes']) ?></p><?php endif; ?>
<section class="table-scroll" aria-label="Quotation lines"><table class="detail-table" data-quotation-lines><thead><tr><th>Product</th><th>Quantity</th><th>List price</th><th>Quoted price</th><th>Line total</th><th>Price source</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($quotation['lines'] as $line): $lineTotal = $line['unit_quantity'] * \App\Support\TaxSettings::cents($line['unit_price']); $total += $lineTotal; $base = isset($unitSets[$line['product_id']]) ? $unitSets[$line['product_id']]->baseCode() : ''; ?>
<tr><td><?= $escape($line['sku'] . ' — ' . $line['product_name']) ?></td><td><?= (int) $line['unit_quantity'] . ' ' . $escape($line['unit_code']) . ($line['unit_factor'] > 1 ? ' (' . (int) $line['quantity'] . ' ' . $escape($base) . ')' : '') ?></td>
<td><?= \App\Support\Money::rupiah((float) $line['list_price']) ?></td><td><?= \App\Support\Money::rupiah((float) $line['unit_price']) ?></td><td><?= \App\Support\Money::rupiah($lineTotal / 100) ?></td>
<td><?php $sourceLine = $line; require dirname(__DIR__) . '/partials/price-source.php'; ?></td><td><?= $escape($line['price_reason'] ?? '') ?></td></tr>
<?php endforeach; ?>
<tr><th colspan="4">Total (excl. PPN)</th><td data-quotation-total><?= \App\Support\Money::rupiah($total / 100) ?></td><td colspan="2"></td></tr>
</tbody></table></section>
<?php if ($quotation['can_decide']) { $dialog = ['id' => 'reject-quotation', 'title' => 'Customer rejected ' . $quotation['quotation_number'], 'intro' => 'Record why the customer declined the offer.', 'action' => '/quotations/reject', 'hidden' => ['id' => $quotation['id']], 'label' => 'Rejection reason', 'submit' => 'Record Rejection'] + ($rejectDialog ?? []); require dirname(__DIR__) . '/partials/reason-dialog.php'; } ?>
<div class="form-actions">
<?php foreach ([['can_send', '/quotations/send', 'Mark as Sent'], ['can_decide', '/quotations/accept', 'Customer Accepted'], ['can_convert', '/quotations/convert', 'Convert to Sales Order'], ['can_cancel', '/quotations/cancel', 'Cancel Quotation']] as [$flag, $action, $label]): if (!$quotation[$flag]) { continue; } ?>
<form method="post" action="<?= $action ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $quotation['id'] ?>"><button type="submit"<?= $action === '/quotations/cancel' ? ' class="button-danger"' : '' ?>><?= $label ?></button></form>
<?php endforeach; ?>
<?php if ($quotation['can_decide']): ?><button type="button" class="button-danger" data-dialog-open="reject-quotation">Customer Rejected</button><?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
