<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Order - Inventory & Order Management</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script defer src="/assets/js/http.js"></script>
    <script defer src="/assets/js/ui-helpers.js"></script>
    <script defer src="/assets/js/form-validation.js"></script>
    <script defer src="/assets/js/navigation.js"></script>
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/dialog.js"></script>
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
    <?php $workspaceTitle = $order->orderNumber(); require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <?php
        $detailStatusClass = match ($order->status()) { 'PendingApproval' => 'pending', 'PartiallyFulfilled' => 'partially-fulfilled', default => strtolower(str_replace([' ', '_'], '-', $order->status())) };
        $detailStatusTone = match ($order->status()) {
            'Approved', 'Fulfilled' => 'status-success',
            'PendingApproval', 'PartiallyFulfilled' => 'status-warning',
            'Cancelled' => 'status-danger',
            default => 'status-normal',
        };
        ?>
        <header class="page-header">
            <div><p class="app-title">Sales Order</p><h1><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle">Review order lines and fulfillment actions.</p></div>
            <nav class="toolbar" aria-label="Document actions"><a href="/timeline?kind=SO&amp;id=<?= $order->id() ?>">Transaction Timeline</a><a href="/sales-orders">Sales Orders</a></nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <dl class="detail-summary">
            <div><dt>Status</dt><dd><span class="status-badge <?= $detailStatusTone ?> status-<?= htmlspecialchars($detailStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?></span></dd></div>
            <div><dt>Customer</dt><dd><?= htmlspecialchars(($customers[$order->customerId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Source</dt><dd><?= htmlspecialchars(($warehouses[$order->sourceWarehouseId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <?php
            // Reservation state follows from the status (D-12, ADR-018): reserved at approval, released per shipment as
            // goods are issued (D-14, ADR-020), at cancellation, or when the remaining fulfilment is closed.
            $closure = $closure ?? null;
            $reservedUnits = $order->remainingUnits();
            $unitsLabel = static fn (int $units): string => $units . ' unit' . ($units === 1 ? '' : 's');
            $reservationText = match (true) {
                $closure !== null => 'Released at closure (' . $unitsLabel((int) $closure['closed_units']) . ')',
                $order->status() === 'Approved' => 'Reserved: ' . $unitsLabel($reservedUnits) . ' held at the source since approval',
                $order->status() === 'PartiallyFulfilled' => 'Reserved: ' . $unitsLabel($reservedUnits) . ' held for the unshipped remainder',
                $order->status() === 'Fulfilled' => 'Released at issue',
                in_array($order->status(), ['Draft', 'PendingApproval'], true) => 'Not reserved yet (reserved at approval)',
                default => $order->approvedAt() !== null ? 'Released at cancellation' : 'None',
            };
            $orderedUnits = array_sum(array_map(static fn ($item): int => $item->quantity(), $order->items()));
            ?>
            <div><dt>Stock Reservation</dt><dd><?= htmlspecialchars($reservationText, ENT_QUOTES, 'UTF-8') ?></dd></div>
            <?php if (($quotation ?? null) !== null): ?><div><dt>Quotation</dt><dd data-source-quotation><a href="/quotations/show?id=<?= (int) $quotation['id'] ?>"><?= htmlspecialchars((string) $quotation['quotation_number'], ENT_QUOTES, 'UTF-8') ?></a> (quoted prices)</dd></div><?php endif; ?>
            <div><dt>Fulfilment</dt><dd data-fulfilment>Issued <?= $order->issuedUnits() ?> of <?= $orderedUnits ?> units<?php if ($closure !== null): ?> · remainder closed<?php elseif ($order->remainingUnits() > 0 && $order->issuedUnits() > 0): ?> · <?= $order->remainingUnits() ?> to ship<?php endif; ?></dd></div>
        </dl>

        <?php
        $availability = $availability ?? [];
        // Quantities are stored in base units and shown with the product's unit breakdown (D-16, ADR-022).
        $unitSets = $unitSets ?? [];
        $qty = static fn (int $productId, int $base): string => isset($unitSets[$productId]) ? $unitSets[$productId]->format($base) : (string) $base;
        $lineQty = static fn ($item): string => $item->unitFactor() > 1 ? number_format($item->unitQuantity(), 0, ',', '.') . ' ' . $item->unitCode() . ' (' . number_format($item->quantity(), 0, ',', '.') . ' ' . (isset($unitSets[$item->productId()]) ? $unitSets[$item->productId()]->baseCode() : '') . ')' : $qty($item->productId(), $item->quantity());
        $shortLines = 0;
        foreach ($order->items() as $item) {
            if ($availability !== [] && $item->remainingQuantity() > ($availability[$item->productId()] ?? 0)) { $shortLines++; }
        }
        ?>
        <?php if ($shortLines > 0): ?><p class="alert alert-warning" role="status"><?= $shortLines ?> line<?= $shortLines === 1 ? '' : 's' ?> exceed<?= $shortLines === 1 ? 's' : '' ?> the stock currently available at the source warehouse (on hand minus stock reserved for other approved orders). Approval is refused until enough stock is available.</p><?php endif; ?>
        <?php $priceOverrides = \App\Support\PriceOverrides::summary($order->items()); ?>
        <?php
        // D-19 / ADR-025: show where each list price came from when any line is not plainly master-priced.
        $priceListNames = $priceListNames ?? [];
        $showSources = array_filter($order->items(), static fn ($item): bool => $item->priceSource() !== 'MASTER' || $item->quotationItemId() !== null) !== [];
        ?>
        <?php if ($availability !== [] && in_array($order->status(), ['Approved', 'PartiallyFulfilled'], true)): ?><p class="field-hint">Available at Source includes the quantity reserved for this order's remainder.</p><?php endif; ?>
        <?php $canIssueNow = $canIssue && in_array($order->status(), \App\Entity\SalesOrder::ISSUABLE_STATUSES, true) && $closure === null && $order->remainingUnits() > 0; ?>
        <?php if ($canIssueNow) {
            // Scan to pick (D-18, ADR-024): counts picked units per line and prefills the issue quantities; the server still
            // allocates lots FEFO and checks availability and the reservation when the goods are issued.
            $scanId = 'pick-scan';
            $scanMode = 'pick';
            $scanTitle = 'Scan to pick';
            $scanHelp = 'Each scan counts one unit for the matching line in the line unit; wrong products and over-picks are flagged and never counted into the issue quantity. Issue quantities follow the picked counts; check them and press Issue Goods.';
            require dirname(__DIR__) . '/partials/barcode-scanner.php';
        } ?>
        <section class="table-scroll" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>">
<table class="detail-table">
            <thead><tr><th scope="col">Product</th><th scope="col">Ordered</th><th scope="col">Issued</th><th scope="col">Remaining</th><?php if ($availability !== []): ?><th scope="col">Available at Source</th><?php endif; ?><th scope="col">Selling Price</th><th scope="col">Line Total</th><?php if ($showSources): ?><th scope="col">Price Source</th><?php endif; ?><?php if ($priceOverrides['lines'] > 0): ?><th scope="col">Master Price</th><th scope="col">Difference vs Master</th><th scope="col">Price Reason</th><?php endif; ?><?php if ($canIssueNow): ?><th scope="col">Issue Now</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($order->items() as $item): ?>
                    <?php $lineAvailable = $availability[$item->productId()] ?? 0; ?>
                    <tr>
                        <td><?= htmlspecialchars($productLabels[$item->productId()] ?? ('Product #' . $item->productId()), ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-line-quantity><?= htmlspecialchars($lineQty($item), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($qty($item->productId(), $item->issuedQuantity()), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $closure !== null && $item->remainingQuantity() > 0 ? '0 (closed ' . htmlspecialchars($qty($item->productId(), $item->remainingQuantity()), ENT_QUOTES, 'UTF-8') . ')' : htmlspecialchars($qty($item->productId(), $item->remainingQuantity()), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php if ($availability !== []): ?><td><?= htmlspecialchars($qty($item->productId(), $lineAvailable), ENT_QUOTES, 'UTF-8') ?><?php if ($item->remainingQuantity() > $lineAvailable): ?> <span class="status-badge status-warning">Short <?= htmlspecialchars($qty($item->productId(), $item->remainingQuantity() - $lineAvailable), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td><?php endif; ?>
                        <td><?= \App\Support\Money::rupiah($item->sellingPrice()) ?><?= $item->unitCode() !== null ? ' <small>per ' . htmlspecialchars($item->unitCode(), ENT_QUOTES, 'UTF-8') . '</small>' : '' ?><?php if ($item->isPriceOverridden()): ?> <span class="status-badge status-warning">Negotiated</span><?php endif; ?></td>
                        <td data-line-total><?= \App\Support\Money::rupiah($item->lineTotal()) ?></td>
                        <?php if ($showSources): ?><td><?php $sourceLine = ['price_source' => $item->priceSource(), 'price_list_name' => $priceListNames[$item->priceListId() ?? 0] ?? null]; require dirname(__DIR__) . '/partials/price-source.php'; ?><?= $item->quotationItemId() !== null ? ' <small>quoted</small>' : '' ?></td><?php endif; ?>
                        <?php if ($priceOverrides['lines'] > 0) { require dirname(__DIR__) . '/partials/price-override-cells.php'; } ?>
                        <?php if ($canIssueNow): ?><td><?php if ($item->remainingQuantity() > 0): ?><label class="visually-hidden" for="issue-quantity-<?= $item->id() ?>">Issue quantity for line <?= $item->id() ?> in <?= htmlspecialchars((string) ($item->unitCode() ?? 'units'), ENT_QUOTES, 'UTF-8') ?></label><input id="issue-quantity-<?= $item->id() ?>" form="issue-form" name="quantities[<?= $item->id() ?>]" type="number" min="0" max="<?= $item->remainingUnitQuantity() ?>" step="1" value="<?= $item->remainingUnitQuantity() ?>" inputmode="numeric" data-pick-line="<?= $item->id() ?>" data-product-id="<?= $item->productId() ?>" data-unit-factor="<?= $item->unitFactor() ?>" data-unit-code="<?= htmlspecialchars((string) ($item->unitCode() ?? ''), ENT_QUOTES, 'UTF-8') ?>"> <span class="field-hint"><?= htmlspecialchars((string) ($item->unitCode() ?? ''), ENT_QUOTES, 'UTF-8') ?></span> <span class="pick-badge" data-picked-for="<?= $item->id() ?>" hidden></span><?php else: ?>Complete<?php endif; ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</section>

        <?php if(($rejection??null)!==null): ?><p class="alert">Rejected: <?= htmlspecialchars($rejection['reason'],ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
        <?php if($canApproveOrCancel && $order->status()==='PendingApproval'): ?>
            <?php $dialog = [
                'id' => 'reject-dialog',
                'title' => 'Reject ' . $order->orderNumber(),
                'intro' => 'The order is cancelled and the reason is shown to the sales owner on this page and in the timeline. Stock is not affected.',
                'action' => '/sales-orders/reject',
                'hidden' => ['id' => $order->id()],
                'label' => 'Rejection reason',
                'submit' => 'Reject Order',
            ] + ($rejectDialog ?? []); require dirname(__DIR__) . '/partials/reason-dialog.php'; ?>
        <?php endif; ?>
        <?php if ($closure !== null): ?><p class="alert" data-closure>Remaining fulfilment closed<?= isset($closure['closed_by_name']) ? ' by ' . htmlspecialchars((string) $closure['closed_by_name'], ENT_QUOTES, 'UTF-8') : '' ?><?= isset($closure['created_at']) ? ' on ' . htmlspecialchars((string) $closure['created_at'], ENT_QUOTES, 'UTF-8') : '' ?>: <?= htmlspecialchars((string) $closure['reason'], ENT_QUOTES, 'UTF-8') ?>. <?= (int) $closure['closed_units'] ?> unshipped unit<?= (int) $closure['closed_units'] === 1 ? '' : 's' ?> released from the reservation; issued goods remain recorded.</p><?php endif; ?>
        <?php $shipments = \App\Support\ShipmentHistory::group($movements ?? []); ?>
        <?php if ($shipments !== []): ?>
        <section class="card" aria-labelledby="shipment-history-title"><div class="card-body"><h2 id="shipment-history-title">Shipment History</h2>
            <?php foreach ($shipments as $shipment): ?>
                <div class="shipment" data-shipment="<?= $shipment['number'] ?>">
                    <h3>Shipment <?= $shipment['number'] ?> · <?= htmlspecialchars($shipment['at'], ENT_QUOTES, 'UTF-8') ?> · <?= $shipment['units'] ?> unit<?= $shipment['units'] === 1 ? '' : 's' ?></h3>
                    <?php foreach ($shipment['lines'] as $movement): ?>
                        <p>Issue #<?= $movement->id() ?> · <?= htmlspecialchars($productLabels[$movement->productId()] ?? ('Product #' . $movement->productId()), ENT_QUOTES, 'UTF-8') ?> · quantity <?= htmlspecialchars($qty($movement->productId(), $movement->quantity()), ENT_QUOTES, 'UTF-8') ?><?php if ($movement->lotId() !== null): ?> · lot <span data-lot-code><?php if ($canIssue): ?><a href="/lots/show?id=<?= (int) $movement->lotId() ?>"><?= htmlspecialchars((string) $movement->lotCode(), ENT_QUOTES, 'UTF-8') ?></a><?php else: ?><?= htmlspecialchars((string) $movement->lotCode(), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span><?= $movement->lotExpiry() !== null ? ' (exp. ' . htmlspecialchars($movement->lotExpiry(), ENT_QUOTES, 'UTF-8') . ')' : '' ?><?php endif; ?><?php if ($canIssue): ?> <a href="/inventory-operations/create?kind=CustomerReturn&amp;source_ledger_id=<?= $movement->id() ?>">Propose Customer Return</a><?php endif; ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div></section>
        <?php endif; ?>
        <?php if (($deliveryNotes ?? []) !== []): ?>
        <section class="card" aria-labelledby="delivery-notes-title" data-delivery-notes><div class="card-body"><h2 id="delivery-notes-title">Delivery Notes</h2>
            <ul>
            <?php foreach ($deliveryNotes as $deliveryNote): ?>
                <li>Shipment <?= (int) $deliveryNote['shipment_number'] ?> · <a href="/delivery-notes/show?id=<?= (int) $deliveryNote['id'] ?>"><?= htmlspecialchars((string) $deliveryNote['delivery_number'], ENT_QUOTES, 'UTF-8') ?></a> · <?= htmlspecialchars((string) $deliveryNote['shipped_at'], ENT_QUOTES, 'UTF-8') ?> · <?= (int) $deliveryNote['units'] ?> units<?php if ($canIssue && !$canApproveOrCancel): /* Warehouse has no invoice access. */ ?><?php elseif ($deliveryNote['invoice_id'] !== null): ?> · invoice <a href="/invoices/show?id=<?= (int) $deliveryNote['invoice_id'] ?>"><?= htmlspecialchars((string) $deliveryNote['invoice_number'], ENT_QUOTES, 'UTF-8') ?></a><?php else: ?> · not invoiced<?php endif; ?></li>
            <?php endforeach; ?>
            </ul>
        </div></section>
        <?php endif; ?>
        <?php if (($costing ?? null) !== null && $costing['notes'] !== []): require dirname(__DIR__) . '/partials/costing.php'; endif; ?>
        <?php if ($canApproveOrCancel && $order->status() === \App\Entity\SalesOrder::STATUS_PARTIALLY_FULFILLED && $closure === null): ?>
            <?php $dialog = [
                'id' => 'close-remaining-dialog',
                'title' => 'Close remaining fulfilment of ' . $order->orderNumber(),
                'intro' => 'The unshipped remainder (' . $order->remainingUnits() . ' units) will not be delivered. Its reservation is released; issued goods and their ledger rows stay recorded and the order stays PartiallyFulfilled.',
                'action' => '/sales-orders/close-remaining',
                'hidden' => ['id' => $order->id()],
                'label' => 'Reason to close the remaining fulfilment',
                'submit' => 'Close Remaining Fulfilment',
            ] + ($closeDialog ?? []); require dirname(__DIR__) . '/partials/reason-dialog.php'; ?>
        <?php endif; ?>
        <?php if ($order->status() === \App\Entity\SalesOrder::STATUS_PENDING_APPROVAL && $priceOverrides['lines'] > 0): ?><p class="alert alert-warning" role="status" data-price-override-notice><?= $priceOverrides['lines'] ?> line<?= $priceOverrides['lines'] === 1 ? '' : 's' ?> priced away from master; total difference <?= \App\Support\PriceOverrides::signedRupiah($priceOverrides['total']) ?>. Check the reasons above before approving.</p><?php endif; ?>
        <div class="form-actions">
        <?php if ($canSubmit): ?>
            <form method="post" action="/sales-orders/submit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Submit</button>
            </form>
        <?php endif; ?>
        <?php if ($canApproveOrCancel && $order->status() === \App\Entity\SalesOrder::STATUS_PENDING_APPROVAL): ?>
            <form method="post" action="/sales-orders/approve">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Approve</button>
            </form>
            <button type="button" class="button-danger" data-dialog-open="reject-dialog">Reject Order</button>
        <?php endif; ?>
        <?php if ($canApproveOrCancel && in_array($order->status(), [\App\Entity\SalesOrder::STATUS_DRAFT, \App\Entity\SalesOrder::STATUS_PENDING_APPROVAL, \App\Entity\SalesOrder::STATUS_APPROVED], true)): ?>
            <form method="post" action="/sales-orders/cancel">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Cancel</button>
            </form>
        <?php endif; ?>
        <?php if ($canIssueNow): ?>
            <form method="post" action="/sales-orders/issue" id="issue-form">
                <input type="hidden" name="operation_key" value="<?= bin2hex(random_bytes(16)) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <p class="field-hint" data-fefo-hint>Lots are picked automatically, earliest expiry first (FEFO); expired lots are never shipped.</p>
                <?php // D-19 / ADR-025: printed on the delivery note (surat jalan) created with this shipment. ?>
                <label class="field"><span class="field-label">Vehicle (optional)</span><input name="vehicle" maxlength="40" autocomplete="off" placeholder="e.g. B 9123 KXT"></label>
                <label class="field"><span class="field-label">Driver (optional)</span><input name="driver" maxlength="80" autocomplete="off"></label>
                <button type="submit">Issue Goods</button>
            </form>
        <?php endif; ?>
        <?php if ($canApproveOrCancel && $order->status() === \App\Entity\SalesOrder::STATUS_PARTIALLY_FULFILLED && $closure === null): ?>
            <button type="button" class="button-danger" data-dialog-open="close-remaining-dialog">Close Remaining Fulfilment</button>
        <?php endif; ?>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
