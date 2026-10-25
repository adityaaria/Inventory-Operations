<?php
// Stock count detail, counting sheet and review (D-18, ADR-024). $blind hides snapshot, expected, variance and value
// (the service never sends them to counters while the count can still be counted).
$c = $count;
$appTitle = 'Stock Counts';
$title = 'Stock Count #' . (int) $c['id'];
$subtitle = $blind
    ? 'Blind count: count what is physically on the shelf. System quantities are hidden until the count is reviewed.'
    : 'Expected = snapshot + stock movements of the lot between the snapshot and the count; variance = counted − expected. Posting applies the variance to the current lot quantity.';
$toolbar = '<a class="button" href="/stock-counts">Stock Counts</a><a class="button" href="/reports/stock-counts">Count Report</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
$csrf = $escape($GLOBALS['csrf_token'] ?? '');
$statusTone = match ((string) $c['status']) {
    'Posted' => 'status-success',
    'Counting', 'RecountRequested' => 'status-warning',
    'Cancelled' => 'status-danger',
    default => 'status-normal',
};
$oldCounts = is_array($old['counts'] ?? null) ? $old['counts'] : [];
$oldCount = static function (int $lineId, string $field) use ($oldCounts): string {
    $value = is_array($oldCounts[$lineId] ?? null) ? ($oldCounts[$lineId][$field] ?? '') : '';
    return is_scalar($value) ? (string) $value : '';
};
$money = static fn ($value): string => $value === null ? '—' : \App\Support\Money::rupiah((float) $value);
$scopeLabel = $c['scope'] === 'Category' ? 'Category: ' . ($c['category_name'] ?? '#' . $c['category_id']) : ($c['scope'] === 'Products' ? count($c['product_ids']) . ' selected products' : 'All active products');
$countable = array_filter($lines, static fn (array $line): bool => $line['countable']);
?>
<dl class="detail-summary" data-count-status="<?= $escape($c['status']) ?>">
    <div><dt>Status</dt><dd><span class="status-badge <?= $statusTone ?>"><?= $escape($c['status']) ?></span><?= $round > 0 ? ' · recount round ' . $round : '' ?></dd></div>
    <div><dt>Warehouse</dt><dd><?= $escape($c['warehouse_name'] ?? '') ?></dd></div>
    <div><dt>Scope</dt><dd><?= $escape($scopeLabel) ?></dd></div>
    <div><dt>Counters</dt><dd><?= $escape(implode(', ', $counter_names)) ?></dd></div>
    <div><dt>Created</dt><dd><?= $escape(($c['created_by_name'] ?? '') . ' · ' . $c['created_at']) ?></dd></div>
    <div><dt>Reason</dt><dd><?= $escape($c['reason']) ?></dd></div>
    <?php if ($c['started_at'] !== null): ?><div><dt>Snapshot</dt><dd><?= $escape($c['started_at']) ?><?= $blind ? '' : ' · ledger #' . (int) $c['snapshot_ledger_id'] ?></dd></div><?php endif; ?>
    <?php if ($c['recount_reason'] !== null): ?><div><dt>Recount requested</dt><dd><?= $escape(($c['recount_requested_by_name'] ?? '') . ': ' . $c['recount_reason']) ?></dd></div><?php endif; ?>
    <?php if ($c['approved_at'] !== null): ?><div><dt>Approved</dt><dd><?= $escape(($c['approved_by_name'] ?? '') . ' · ' . $c['approved_at'] . ($c['approval_note'] !== null ? ' · ' . $c['approval_note'] : '')) ?></dd></div><?php endif; ?>
    <?php if ($c['posted_at'] !== null): ?><div><dt>Posted</dt><dd><?= $escape(($c['posted_by_name'] ?? '') . ' · ' . $c['posted_at']) ?></dd></div><?php endif; ?>
    <?php if ($c['cancelled_at'] !== null): ?><div><dt>Cancelled</dt><dd><?= $escape(($c['cancelled_by_name'] ?? '') . ': ' . $c['cancel_reason']) ?></dd></div><?php endif; ?>
</dl>

<?php if (!$blind && $lines !== []): ?>
<section class="metric-grid" aria-label="Variance summary" data-count-review>
    <article class="metric-card"><span>Lines counted</span><strong><?= (int) $summary['counted'] ?> / <?= (int) $summary['lines'] ?></strong></article>
    <article class="metric-card"><span>Lines with a variance</span><strong data-variance-lines><?= (int) $summary['variance_lines'] ?></strong><small class="field-hint"><?= (int) $summary['over_tolerance'] ?> above tolerance (<?= (int) $tolerance->percent ?> % or <?= (int) $tolerance->units ?> unit(s))</small></article>
    <article class="metric-card"><span>Net units</span><strong><?= $summary['net_units'] > 0 ? '+' : '' ?><?= (int) $summary['net_units'] ?></strong><small class="field-hint">base units</small></article>
    <article class="metric-card"><span>Value impact</span><strong data-value-impact><?= $money($summary['value']) ?></strong><small class="field-hint">gain <?= $money($summary['gain_value']) ?> · loss <?= $money($summary['loss_value']) ?></small></article>
</section>
<?php endif; ?>

<?php if ($can['count'] && $lines !== []) {
    $scanId = 'count-scan';
    $scanMode = 'count';
    $scanTitle = $round > 0 ? 'Scan to recount' : 'Scan to count';
    $scanHelp = 'Each scan adds one unit of the scanned barcode to the lot line of that product. When a product has several lots on the sheet, click the quantity field of the lot first. You can also type quantities in any unit. Save counts often; nothing is stored only in this browser.';
    require dirname(__DIR__) . '/partials/barcode-scanner.php';
} ?>

<?php if ($can['count'] && $countable !== []): ?><form method="post" action="/stock-counts/count" id="count-form" class="form"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"></form><?php endif; ?>
<?php if ($can['recount']): ?><form method="post" action="/stock-counts/recount" id="recount-form"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"></form><?php endif; ?>

<section class="table-scroll count-sheet" id="count-sheet" aria-label="Count sheet">
<table class="data-table">
    <thead><tr>
        <th scope="col">Product</th><th scope="col">Lot</th><th scope="col">Expiry</th>
        <?php if (!$blind): ?><th scope="col">Snapshot</th><th scope="col">Expected</th><?php endif; ?>
        <th scope="col">Counted</th>
        <?php if (!$blind): ?><th scope="col">Variance</th><th scope="col">Unit cost</th><th scope="col">Value impact</th><?php endif; ?>
        <th scope="col">Counted by</th>
        <?php if ($can['recount']): ?><th scope="col">Recount</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $line): $units = $line['units']; $lineId = (int) $line['id']; ?>
        <tr data-count-line="<?= $lineId ?>" data-product-id="<?= (int) $line['product_id'] ?>" data-lot-code="<?= $escape($line['lot_code']) ?>" data-base-unit="<?= $escape($units->baseCode()) ?>"<?= !$blind && ($line['exceeds'] ?? false) ? ' data-over-tolerance' : '' ?>>
            <td><?= $escape($line['sku'] . ' — ' . $line['product_name']) ?></td>
            <td><?php if ($line['lot_id'] !== null && !$blind): ?><a href="/lots/show?id=<?= (int) $line['lot_id'] ?>"><?= $escape($line['lot_code']) ?></a><?php elseif ($line['posted_lot_id'] !== null && !$blind): ?><a href="/lots/show?id=<?= (int) $line['posted_lot_id'] ?>"><?= $escape($line['lot_code']) ?></a><?php else: ?><?= $escape($line['lot_code']) ?><?php endif; ?><?php if ($line['origin'] === 'Found'): ?> <span class="status-badge status-warning"><?= $line['new_lot'] ? 'Found lot (new)' : 'Found lot' ?></span><?php endif; ?></td>
            <td><?= $escape($line['expiry_date'] ?? '—') ?></td>
            <?php if (!$blind): ?><td><?= $escape($line['snapshot']) ?></td><td data-expected><?= $escape($line['expected'] ?? '—') ?></td><?php endif; ?>
            <td>
                <?php if ($line['countable']): ?>
                    <div class="count-quantity">
                        <label class="visually-hidden" for="count-<?= $lineId ?>">Counted quantity of <?= $escape($line['label']) ?></label>
                        <input id="count-<?= $lineId ?>" form="count-form" type="number" min="0" step="1" inputmode="numeric" name="counts[<?= $lineId ?>][quantity]" value="<?= $escape($oldCount($lineId, 'quantity')) ?>" data-count-quantity>
                        <label class="visually-hidden" for="count-unit-<?= $lineId ?>">Unit</label>
                        <select id="count-unit-<?= $lineId ?>" form="count-form" name="counts[<?= $lineId ?>][unit]" data-count-unit>
                            <?php foreach (array_reverse($units->active()) as $unit): ?><option value="<?= $escape($unit->code()) ?>" data-factor="<?= $unit->factor() ?>" <?= $oldCount($lineId, 'unit') === $unit->code() ? 'selected' : '' ?>><?= $escape($unit->isBase() ? $unit->code() : $unit->code() . ' (' . $unit->factor() . ' ' . $units->baseCode() . ')') ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($line['flagged_now']): ?><small class="field-hint">Recount requested<?= $line['counted_display'] !== null ? '; first count ' . $escape($line['counted_display']) : '' ?></small><?php elseif ($line['counted_display'] !== null): ?><small class="field-hint">Saved: <?= $escape($line['counted_display']) ?></small><?php endif; ?>
                <?php else: ?>
                    <?php if ($line['recounted_display'] !== null): ?><?= $escape($line['recounted_display']) ?> <small class="field-hint">recount<?= $line['counted_display'] !== null ? '; first count ' . $escape($line['counted_display']) : '' ?></small>
                    <?php elseif ($line['flagged_now']): ?><span class="status-badge status-warning">Recount requested</span>
                    <?php else: ?><?= $escape($line['counted_display'] ?? 'Not counted') ?><?php endif; ?>
                <?php endif; ?>
            </td>
            <?php if (!$blind): ?>
                <td data-variance="<?= $line['variance'] ?? '' ?>" class="<?= ($line['variance'] ?? 0) > 0 ? 'variance-positive' : (($line['variance'] ?? 0) < 0 ? 'variance-negative' : '') ?>"><?= $escape($line['variance_display'] ?? '—') ?><?php if ($line['exceeds'] ?? false): ?> <span class="status-badge status-warning">Above tolerance (±<?= (int) $line['allowance'] ?>)</span><?php endif; ?></td>
                <td><?= $money($line['unit_cost']) ?></td>
                <td><?= $money($line['value']) ?></td>
            <?php endif; ?>
            <td><?= $escape($line['counted_by_name'] ?? '—') ?><?= $line['recounted_by_name'] !== null ? ' · recount ' . $escape($line['recounted_by_name']) : '' ?></td>
            <?php if ($can['recount']): ?><td><?php if ($line['recountable'] ?? false): ?><label><input type="checkbox" form="recount-form" name="line_ids[]" value="<?= $lineId ?>" checked> Recount</label><?php else: ?>—<?php endif; ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if ($lines === []): ?><tr><td colspan="11"><?= $c['status'] === 'Draft' ? 'The sheet is created when the count is started (snapshot of every lot with stock in scope).' : 'No lot of this scope had stock at the snapshot. Enter found lots or cancel the count.' ?></td></tr><?php endif; ?>
    </tbody>
</table>
</section>

<?php if ($can['count'] && $countable !== []): ?><div class="form-actions"><button type="submit" form="count-form" class="button-primary">Save Counts</button></div><?php endif; ?>

<?php if ($can['found']): ?>
<section class="card" aria-labelledby="found-lot-title"><div class="card-body">
    <h2 id="found-lot-title">Found lot</h2>
    <p class="field-hint">Goods of a product in scope whose lot is not on the sheet. An existing lot without stock at the snapshot is added as a line; an unknown lot is created when the count is posted, at the product's current purchase price per <?= 'base unit' ?>.</p>
    <form method="post" action="/stock-counts/found-lot" class="form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <label class="field"><span class="field-label">Product</span><select name="product_id" required data-found-product><option value="">Choose a product</option><?php foreach ($scope_products as $entry): ?><option value="<?= $entry['product']->id() ?>" data-units="<?= $escape(json_encode(array_map(static fn ($unit): array => ['code' => $unit->code(), 'factor' => $unit->factor()], $entry['units']->active()))) ?>" <?= (string) ($old['product_id'] ?? '') === (string) $entry['product']->id() ? 'selected' : '' ?>><?= $escape($entry['product']->sku() . ' — ' . $entry['product']->name() . ($entry['product']->tracksExpiry() ? ' (expiry required)' : '')) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span class="field-label">Lot code</span><input name="lot_code" maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9._/\-]*" required value="<?= $escape(is_scalar($old['lot_code'] ?? null) ? $old['lot_code'] : '') ?>"></label>
        <label class="field"><span class="field-label">Expiry date (required for products that track expiry)</span><input type="date" name="expiry_date" value="<?= $escape(is_scalar($old['expiry_date'] ?? null) ? $old['expiry_date'] : '') ?>"></label>
        <label class="field"><span class="field-label">Quantity</span><input type="number" name="quantity" min="1" step="1" required value="<?= $escape(is_scalar($old['quantity'] ?? null) ? $old['quantity'] : '') ?>"></label>
        <label class="field"><span class="field-label">Unit (empty = base unit)</span><input name="unit_code" maxlength="30" data-found-unit value="<?= $escape(is_scalar($old['unit_code'] ?? null) ? $old['unit_code'] : '') ?>"></label>
        <div class="form-actions"><button type="submit">Add Found Lot</button></div>
    </form>
</div></section>
<?php endif; ?>

<?php if ($can['recount']): ?>
<section class="card" aria-labelledby="recount-title"><div class="card-body">
    <h2 id="recount-title">Request recount</h2>
    <p class="field-hint">Only lines above the tolerance can be recounted, once each. A different counter than the first count must recount a line; add a counter if needed.</p>
    <label class="field"><span class="field-label">Reason</span><textarea form="recount-form" name="reason" maxlength="500" required></textarea></label>
    <label class="field"><span class="field-label">Add counters (optional)</span><select form="recount-form" name="counter_ids[]" multiple size="4"><?php foreach ($counters_available as $user): if (in_array($user->id(), $c['counters'], true)) { continue; } ?><option value="<?= $user->id() ?>"><?= $escape($user->name() . ' (' . $user->role() . ')') ?></option><?php endforeach; ?></select></label>
    <div class="form-actions"><button type="submit" form="recount-form">Request Recount</button></div>
</div></section>
<?php endif; ?>

<div class="form-actions" data-count-actions>
    <?php if ($can['start']): ?><form method="post" action="/stock-counts/start"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="button-primary">Start Counting (take snapshot)</button></form><?php endif; ?>
    <?php if ($can['submit']): ?><form method="post" action="/stock-counts/submit"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit">Submit for Review</button></form><?php endif; ?>
    <?php if ($can['approve']): ?><form method="post" action="/stock-counts/approve" class="form-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><label class="visually-hidden" for="approval-note">Approval note</label><input id="approval-note" name="note" maxlength="500" placeholder="Approval note (optional)"><button type="submit" class="button-primary">Approve Variances</button></form><?php endif; ?>
    <?php if ($c['status'] === 'Submitted' && $reviewer && !$can['approve']): ?><p class="field-hint">You counted lines of this count; another Admin approves it.</p><?php endif; ?>
    <?php if ($can['post']): ?><form method="post" action="/stock-counts/post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="button-primary">Post Variances to Stock</button></form><?php endif; ?>
    <?php if ($can['cancel']): ?><form method="post" action="/stock-counts/cancel" class="form-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><label class="visually-hidden" for="cancel-reason">Cancellation reason</label><input id="cancel-reason" name="reason" maxlength="500" required placeholder="Reason to cancel"><button type="submit" class="button-danger">Cancel Count</button></form><?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/inventory-operations/end.php'; ?>
