<?php
// Movement valuation / reconciliation (D-20, ADR-026), Admin only: per warehouse, opening value + movements at lot cost =
// closing value, plus the in-transit line and the total. Opening and closing come from the lot values rolled back by the
// ledger cost amounts; a difference other than 0 would expose a broken invariant.
$current = 'reconciliation';
require __DIR__ . '/nav.php';
$report ??= null;
$appTitle = 'Reports'; $title = 'Valuation Reconciliation';
$subtitle = $report === null ? 'Movement valuation by warehouse.' : 'Business dates ' . $report['from'] . ' to ' . $report['to'] . ' (' . \App\Support\BusinessDate::timezone()->getName() . '): opening + receipts + transfers in − issues (COGS) − transfers out ± adjustments and counts − write-offs and losses = closing.';
$csvQuery = $report === null ? '' : http_build_query(['from' => $report['from'], 'to' => $report['to']]);
$toolbar = ($report === null ? '' : '<a class="button" href="/reports/valuation-reconciliation.csv?' . htmlspecialchars($csvQuery, ENT_QUOTES, 'UTF-8') . '" data-export="server">Export CSV</a>') . $valuationNav;
require dirname(__DIR__) . '/inventory-operations/start.php';
?>
<form method="get" action="/reports/valuation-reconciliation" class="filters"><label>From <input type="date" name="from" value="<?= $escape($report['from'] ?? ($query['from'] ?? '')) ?>"></label><label>To <input type="date" name="to" value="<?= $escape($report['to'] ?? ($query['to'] ?? '')) ?>"></label><button type="submit">Apply</button></form>
<?php if ($report !== null): ?>
<p class="<?= $report['reconciled'] ? 'alert alert-success' : 'alert alert-danger' ?>" data-reconciled="<?= $report['reconciled'] ? '1' : '0' ?>" role="status"><?= $report['reconciled'] ? 'Reconciled to the cent: every line\'s opening value plus its movements equals its closing value.' : 'Not reconciled: a line differs; an invariant is broken (see the Difference column).' ?></p>
<p class="field-hint" data-valuation-start><?= $escape($valuationStartNote($report['start'])) ?><?= $report['opening_at_valuation_start'] ? ' The period starts before the valuation start, so the opening value is the value at the valuation start.' : '' ?><?= $report['unvalued_movements'] > 0 ? ' ' . (int) $report['unvalued_movements'] . ' ledger rows in this period predate the valuation start and are not valued.' : '' ?></p>
<section class="table-scroll" aria-label="Reconciliation"><table class="data-table" data-reconciliation><thead><tr><th>Line</th><?php foreach (\App\Service\InventoryValuationService::COLUMNS as $label): ?><th><?= $escape($label) ?></th><?php endforeach; ?><th>Difference</th></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): ?><tr data-reconciliation-line="<?= $escape($row['kind']) ?>"><?= $row['kind'] === 'total' ? '<th>' : '<td>' ?><?= $escape($row['label']) ?><?= $row['kind'] === 'total' ? '</th>' : '</td>' ?>
<?php foreach (array_keys(\App\Service\InventoryValuationService::COLUMNS) as $column): ?><td data-column="<?= $column ?>" data-cents="<?= (int) $row[$column] ?>"><?= $rupiahCents((int) $row[$column]) ?></td><?php endforeach; ?>
<td data-difference="<?= (int) $row['difference'] ?>"><?= $rupiahCents((int) $row['difference']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></section>
<p class="field-hint">Transfers out leave a warehouse into transit and enter the destination from it at the same lot cost; damaged and short units are transit losses at that cost. Transfers in of shipments dispatched before the valuation start have no transit counterpart. Adjustments include supplier and customer returns.</p>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
