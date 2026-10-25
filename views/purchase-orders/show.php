<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Order - Inventory & Order Management</title>
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
        $detailStatusClass = $order->status() === 'PartiallyReceived' ? 'partial' : strtolower(str_replace([' ', '_'], '-', $order->status()));
        $detailStatusTone = match ($order->status()) {
            'Received' => 'status-success',
            'PartiallyReceived', 'Ordered' => 'status-warning',
            'Cancelled' => 'status-danger',
            default => 'status-normal',
        };
        ?>
        <header class="page-header">
            <div><p class="app-title">Purchase Order</p><h1><?= htmlspecialchars($order->orderNumber(), ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle">Review order lines and available receiving actions.</p></div>
            <nav class="toolbar" aria-label="Document actions"><a href="/timeline?kind=PO&amp;id=<?= $order->id() ?>">Transaction Timeline</a><a href="/purchase-orders">Purchase Orders</a></nav>
        </header>
        <?php if (($error ?? '') !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <dl class="detail-summary">
            <div><dt>Status</dt><dd><span class="status-badge <?= $detailStatusTone ?> status-<?= htmlspecialchars($detailStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?></span></dd></div>
            <div><dt>Supplier</dt><dd><?= htmlspecialchars(($suppliers[$order->supplierId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>Destination</dt><dd><?= htmlspecialchars(($warehouses[$order->destinationWarehouseId()] ?? null)?->name() ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></dd></div>
        </dl>

        <?php $receiptInput = $receiptInput ?? []; $trackedProducts = $trackedProducts ?? [];
        // Attempted lot input is kept on a 422 for the line that was submitted (D-17, ADR-023).
        $lotOld = static function (int $itemId, string $field, int $row) use ($receiptInput): string {
            if ((int) ($receiptInput['item_id'] ?? 0) !== $itemId) { return ''; }
            $value = $field === 'quantity' ? ($receiptInput['quantity'] ?? '') : (is_array($receiptInput[$field] ?? null) ? ($receiptInput[$field][$row] ?? '') : '');
            return is_scalar($value) ? (string) $value : '';
        };
        $priceOverrides = \App\Support\PriceOverrides::summary($order->items());
        // Quantities are stored in base units and shown with the product's unit breakdown (D-16, ADR-022).
        $unitSets = $unitSets ?? [];
        $qty = static fn (int $productId, int $base): string => isset($unitSets[$productId]) ? $unitSets[$productId]->format($base) : (string) $base;
        $lineQty = static fn ($item): string => $item->unitFactor() > 1 ? number_format($item->unitQuantity(), 0, ',', '.') . ' ' . $item->unitCode() . ' (' . number_format($item->quantity(), 0, ',', '.') . ' ' . (isset($unitSets[$item->productId()]) ? $unitSets[$item->productId()]->baseCode() : '') . ')' : $qty($item->productId(), $item->quantity()); ?>
        <?php
        // Scan to receive (D-18, ADR-024): a unit barcode increments the matching line's receive quantity in its unit.
        $receivableLines = $canReceive && in_array($order->status(), \App\Entity\PurchaseOrder::RECEIVABLE_STATUSES, true) && ($closure ?? null) === null
            ? count(array_filter($order->items(), static fn ($item): bool => $item->remainingQuantity() > 0)) : 0;
        if ($receivableLines > 0) {
            $scanId = 'receive-scan';
            $scanMode = 'receive';
            $scanTitle = 'Scan to receive';
            $scanHelp = 'Scan each carton or piece with a USB/Bluetooth scanner (or type the code and press Enter): the matching line\'s receive quantity goes up by one unit of its line unit. A unit is converted only when it is a whole number of line units. Lots and expiry are entered per line as before; then press Receive on the line.';
            require dirname(__DIR__) . '/partials/barcode-scanner.php';
        }
        ?>
        <section class="table-scroll" aria-label="<?= htmlspecialchars($workspaceTitle . ' table', ENT_QUOTES, 'UTF-8') ?>">
<table class="detail-table">
            <thead><tr><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col">Received</th><th scope="col">Remaining</th><th scope="col">Purchase Price</th><th scope="col">Line Total</th><?php if ($priceOverrides['lines'] > 0): ?><th scope="col">Master Price</th><th scope="col">Difference vs Master</th><th scope="col">Price Reason</th><?php endif; ?><th scope="col">Receive</th></tr></thead>
            <tbody>
                <?php foreach ($order->items() as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($productLabels[$item->productId()] ?? ('Product #' . $item->productId()), ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-line-quantity><?= htmlspecialchars($lineQty($item), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($qty($item->productId(), $item->receivedQuantity()), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($qty($item->productId(), $item->remainingQuantity()), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= \App\Support\Money::rupiah($item->purchasePrice()) ?><?= $item->unitCode() !== null ? ' <small>per ' . htmlspecialchars($item->unitCode(), ENT_QUOTES, 'UTF-8') . '</small>' : '' ?><?php if ($item->isPriceOverridden()): ?> <span class="status-badge status-warning">Negotiated</span><?php endif; ?></td>
                        <td data-line-total><?= \App\Support\Money::rupiah($item->lineTotal()) ?></td>
                        <?php if ($priceOverrides['lines'] > 0) { require dirname(__DIR__) . '/partials/price-override-cells.php'; } ?>
                        <td>
                            <?php if ($canReceive && in_array($order->status(), \App\Entity\PurchaseOrder::RECEIVABLE_STATUSES, true) && $item->remainingQuantity() > 0 && ($closure ?? null) === null): ?>
                                <form method="post" action="/purchase-orders/receive" data-scan-line="<?= $item->id() ?>" data-product-id="<?= $item->productId() ?>" data-unit-factor="<?= $item->unitFactor() ?>" data-unit-code="<?= htmlspecialchars((string) ($item->unitCode() ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-remaining="<?= $item->remainingUnitQuantity() ?>">
                <input type="hidden" name="operation_key" value="<?= bin2hex(random_bytes(16)) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= $order->id() ?>">
                                    <input type="hidden" name="item_id" value="<?= $item->id() ?>">
                                    <label class="visually-hidden" for="receive-quantity-<?= $item->id() ?>">Receive quantity in <?= htmlspecialchars((string) ($item->unitCode() ?? 'units'), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="receive-quantity-<?= $item->id() ?>" name="quantity" type="number" min="1" max="<?= $item->remainingUnitQuantity() ?>" required value="<?= htmlspecialchars($lotOld($item->id(), 'quantity', 0), ENT_QUOTES, 'UTF-8') ?>"> <span class="field-hint"><?= htmlspecialchars((string) ($item->unitCode() ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php $isTracked = $trackedProducts[$item->productId()] ?? false; $lotRows = max(1, count(is_array($receiptInput['lot_code'] ?? null) && (int) ($receiptInput['item_id'] ?? 0) === $item->id() ? $receiptInput['lot_code'] : [])); ?>
                                    <fieldset class="lot-splits" data-lot-splits>
                                        <legend>Lots<?= $isTracked ? ' (expiry required)' : ' (optional; default lot = order number)' ?></legend>
                                        <?php for ($row = 0; $row < $lotRows; $row++): ?>
                                        <div class="lot-split" data-lot-split>
                                            <label>Lot code <input name="lot_code[]" maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9._/\-]*"<?= $isTracked ? ' required' : '' ?> value="<?= htmlspecialchars($lotOld($item->id(), 'lot_code', $row), ENT_QUOTES, 'UTF-8') ?>"></label>
                                            <label>Expiry <input name="expiry_date[]" type="date"<?= $isTracked ? ' required' : '' ?> value="<?= htmlspecialchars($lotOld($item->id(), 'expiry_date', $row), ENT_QUOTES, 'UTF-8') ?>"></label>
                                            <label>Quantity (<?= htmlspecialchars((string) ($item->unitCode() ?? ''), ENT_QUOTES, 'UTF-8') ?>) <input name="lot_quantity[]" type="number" min="1" placeholder="all" value="<?= htmlspecialchars($lotOld($item->id(), 'lot_quantity', $row), ENT_QUOTES, 'UTF-8') ?>"></label>
                                        </div>
                                        <?php endfor; ?>
                                        <button type="button" class="button button-quiet" data-lot-split-add>Add another lot</button>
                                        <p class="field-hint">Split the received quantity over several lots when the delivery has more than one batch; lot quantities must add up to it. With one lot, leave its quantity empty.</p>
                                    </fieldset>
                                    <button type="submit">Receive</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</section>

        <?php if(($closure??null)!==null): ?><p class="alert">Remaining supply closed: <?= htmlspecialchars($closure['reason'],ENT_QUOTES,'UTF-8') ?>. Received quantities remain recorded.</p><?php endif; ?>
        <?php if($canOrderOrCancel && $order->status()==='PartiallyReceived' && ($closure??null)===null): ?>
        <form method="post" action="/purchase-orders/close-remainder" class="form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($GLOBALS['csrf_token']??''),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="id" value="<?= $order->id() ?>"><label class="field"><span class="field-label">Reason to close remaining supply</span><textarea name="reason" maxlength="500" required></textarea></label><div class="form-actions"><button type="submit">Close Remaining Supply</button></div></form>
        <?php endif; ?>
        <?php if(($movements??[])!==[]): ?><section class="table-scroll" aria-label="Lots received"><h2>Lots Received</h2><table class="data-table"><thead><tr><th scope="col">Receipt</th><th scope="col">Product</th><th scope="col">Lot</th><th scope="col">Expiry</th><th scope="col">Quantity</th><th scope="col">Received at</th><?php if($canReceive): ?><th scope="col">Action</th><?php endif; ?></tr></thead><tbody><?php foreach($movements as $movement): ?><tr><td>#<?= $movement->id() ?></td><td><?= htmlspecialchars($productLabels[$movement->productId()] ?? ('Product #' . $movement->productId()), ENT_QUOTES, 'UTF-8') ?></td><td data-lot-code><?php if ($movement->lotId() !== null): ?><?php if ($canReceive): ?><a href="/lots/show?id=<?= (int) $movement->lotId() ?>"><?= htmlspecialchars((string) $movement->lotCode(), ENT_QUOTES, 'UTF-8') ?></a><?php else: ?><?= htmlspecialchars((string) $movement->lotCode(), ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php else: ?>—<?php endif; ?></td><td><?= htmlspecialchars($movement->lotExpiry() ?? '—', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($qty($movement->productId(), $movement->quantity()), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $movement->createdAt(), ENT_QUOTES, 'UTF-8') ?></td><?php if($canReceive): ?><td><a href="/inventory-operations/create?kind=SupplierReturn&amp;source_ledger_id=<?= $movement->id() ?>">Propose Supplier Return</a></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
        <?php if ($order->status() === \App\Entity\PurchaseOrder::STATUS_DRAFT && $priceOverrides['lines'] > 0): ?><p class="alert alert-warning" role="status" data-price-override-notice><?= $priceOverrides['lines'] ?> line<?= $priceOverrides['lines'] === 1 ? '' : 's' ?> priced away from master; total difference <?= \App\Support\PriceOverrides::signedRupiah($priceOverrides['total']) ?>. Check the negotiated supplier prices and reasons above before marking the order as Ordered.</p><?php endif; ?>
        <div class="form-actions">
        <?php if ($canOrderOrCancel && $order->status() === \App\Entity\PurchaseOrder::STATUS_DRAFT): ?>
            <form method="post" action="/purchase-orders/order">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Mark Ordered</button>
            </form>
        <?php endif; ?>
        <?php if ($canOrderOrCancel && in_array($order->status(), [\App\Entity\PurchaseOrder::STATUS_DRAFT, \App\Entity\PurchaseOrder::STATUS_ORDERED], true)): ?>
            <form method="post" action="/purchase-orders/cancel">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $order->id() ?>">
                <button type="submit">Cancel</button>
            </form>
        <?php endif; ?>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
