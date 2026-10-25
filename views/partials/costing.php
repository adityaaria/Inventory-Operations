<?php
// COGS and margin of shipments (D-20, ADR-026), Admin only: the controller passes $costing only for Admin. COGS = the lots
// each shipment took (FEFO, ADR-023) × their unit cost (= −Σ cost_amount of its Issue rows); margin = the live invoice's
// net amount (excl. PPN, ADR-025) − COGS. Shipments made before the valuation start have no cost.
$costingMoney = static fn (int $cents): string => \App\Support\Money::rupiah($cents / 100);
$costingPercent = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1, ',', '.') . ' %';
$costingEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="card no-print" aria-labelledby="costing-title" data-costing><div class="card-body"><h2 id="costing-title">Cost of goods and margin (Admin only)</h2>
<p class="field-hint">COGS = lots shipped × their lot unit cost (FIFO per batch); margin = invoice net amount (excl. PPN) − COGS. Not shown to Sales or Warehouse.</p>
<section class="table-scroll"><table class="data-table" data-costing-lines><thead><tr><th>Shipment</th><th>Product</th><th>Lots × unit cost</th><th>COGS</th><th>Invoiced (net)</th><th>Margin</th><th>%</th></tr></thead><tbody>
<?php foreach ($costing['notes'] as $costingNote): foreach ($costingNote['lines'] as $costingLine): ?>
<tr><td><a href="/delivery-notes/show?id=<?= (int) $costingNote['delivery_note_id'] ?>"><?= $costingEscape($costingNote['delivery_number']) ?></a></td><td><?= $costingEscape($costingLine['sku'] . ' — ' . $costingLine['product_name']) ?> (<?= (int) $costingLine['unit_quantity'] . ' ' . $costingEscape($costingLine['unit_code']) ?>)</td>
<td><?php foreach ($costingLine['lots'] as $costingLot): ?><div data-costing-lot><?= $costingEscape($costingLot['lot_code'] ?? 'pre-lot stock') ?>: <?= (int) $costingLot['quantity'] ?> × <?= $costingLot['unit_cost'] === null ? 'no cost (before valuation start)' : \App\Support\Money::rupiah((float) $costingLot['unit_cost']) . ' = ' . $costingMoney((int) $costingLot['cost_cents']) ?></div><?php endforeach; ?></td>
<td data-cogs-cents="<?= (int) $costingLine['cogs_cents'] ?>"><?= $costingLine['valued'] ? $costingMoney((int) $costingLine['cogs_cents']) : 'before valuation start' ?></td>
<td><?= $costingLine['invoiced'] ? $costingMoney((int) $costingLine['revenue_cents']) : 'not invoiced' ?></td>
<td><?= $costingLine['invoiced'] && $costingLine['valued'] ? $costingMoney((int) $costingLine['margin_cents']) : '—' ?></td><td><?= $costingLine['invoiced'] ? $costingPercent($costingLine['margin_percent']) : '—' ?></td></tr>
<?php endforeach; endforeach; ?>
<?php $costingSummary = $costing['summary']; ?>
<tr><th colspan="3">Total COGS shipped</th><th data-costing-cogs="<?= (int) $costingSummary['cogs_cents'] ?>"><?= $costingMoney((int) $costingSummary['cogs_cents']) ?></th><th><?= $costingMoney((int) $costingSummary['invoiced_revenue_cents']) ?></th><th data-costing-margin="<?= (int) $costingSummary['invoiced_margin_cents'] ?>"><?= $costingMoney((int) $costingSummary['invoiced_margin_cents']) ?></th><th><?= $costingPercent($costingSummary['invoiced_margin_percent']) ?></th></tr>
<?php if ($costingSummary['not_invoiced_cogs_cents'] > 0): ?><tr><td colspan="3">of which shipped, not invoiced</td><td><?= $costingMoney((int) $costingSummary['not_invoiced_cogs_cents']) ?></td><td colspan="3"></td></tr><?php endif; ?>
</tbody></table></section>
</div></section>
