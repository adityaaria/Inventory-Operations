<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StockLot;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\StockLedgerRepositoryInterface;
use App\Repository\Contract\StockRepositoryInterface;
use App\Support\RequestOrigin;
use InvalidArgumentException;
use Throwable;

final class StockService
{
    private const CATALOG_REQUIRED = 'Catalog repository is required.';

    private bool $transactionActive = false;
    public function __construct(
        private readonly StockRepositoryInterface $stocks,
        private readonly StockLedgerRepositoryInterface $ledger,
        private readonly ?AuditLogRepositoryInterface $audit = null,
        private readonly ?\App\Repository\Contract\StockCatalogRepositoryInterface $catalog = null,
        private readonly ?\App\Repository\Contract\TransactionManagerInterface $transactions = null,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->transactions !== null) { return $this->transactions->run($operation); }
        if ($this->transactionActive) {
            return $operation();
        }
        $this->stocks->beginTransaction();
        $this->transactionActive = true;
        try {
            $result = $operation();
            $this->stocks->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->stocks->rollBack();
            throw $exception;
        } finally {
            $this->transactionActive = false;
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function catalogTransaction(callable $operation): mixed
    {
        return $this->transaction(function () use ($operation): mixed {
            if ($this->catalog === null) { throw new \LogicException(self::CATALOG_REQUIRED); }
            $this->catalog->lockCreation();
            return $operation();
        });
    }

    private function catalogRepository(): \App\Repository\Contract\StockCatalogRepositoryInterface
    {
        return $this->catalog ?? throw new \LogicException(self::CATALOG_REQUIRED);
    }

    /** Initialize empty balances only: never increment/decrement quantities or fabricate zero ledger entries. */
    public function initializeProduct(int $productId): void
    {
        $this->catalogTransaction(function () use ($productId): void {
            foreach ($this->catalogRepository()->warehouseIds() as $warehouseId) { $this->stocks->lockByProductWarehouse($productId, $warehouseId); }
        });
    }

    public function initializeWarehouse(int $warehouseId): void
    {
        $this->catalogTransaction(function () use ($warehouseId): void {
            foreach ($this->catalogRepository()->productIds() as $productId) { $this->stocks->lockByProductWarehouse($productId, $warehouseId); }
        });
    }

    /** Idempotent repair for historical missing pairs, one product per transaction. */
    public function initializeCatalog(): void
    {
        if ($this->catalog === null) { throw new \LogicException(self::CATALOG_REQUIRED); }
        foreach ($this->catalogRepository()->productIds() as $productId) { $this->initializeProduct($productId); }
    }

    /**
     * Receipt (D-17, ADR-023): every movement enters one or more lots. Splits must add up to the movement; without splits
     * one lot is used ($defaultLotCode or "<reference>-<id>", no expiry, $defaultUnitCost or the current purchase price per
     * base). A lot with the same code, expiry and cost is merged. A product that tracks expiry needs an expiry date, and an
     * already expired date is refused. One Receipt ledger row per lot. $afterMovements receives the lots received.
     *
     * @param list<StockMovement> $movements
     */
    public function receive(
        int $warehouseId,
        array $movements,
        int $performedBy,
        string $referenceType,
        int $referenceId,
        ?callable $afterMovements = null,
    ): void {
        $this->assertCommonInput($warehouseId, $movements, $performedBy, $referenceType, $referenceId, 'Receipt');
        $this->assertSplits($movements);

        usort(
            $movements,
            static fn (StockMovement $a, StockMovement $b): int => $a->productId() <=> $b->productId(),
        );

        $this->transaction(function () use ($warehouseId, $movements, $performedBy, $referenceType, $referenceId, $afterMovements): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $warehouseId);
            }
            $lots = $this->lockLotsFor($warehouseId, array_map(static fn (StockMovement $movement): int => $movement->productId(), $movements));
            $today = $this->stocks->today();
            $received = [];
            foreach ($movements as $movement) {
                $product = $movement->productId();
                $this->stocks->increment($product, $warehouseId, $movement->quantity());
                foreach ($this->splitsOf($movement, $referenceType, $referenceId) as $split) {
                    $this->assertLotPolicy($product, $split, $today, true);
                    $lot = $this->resolveLot($product, $warehouseId, $lots[$product], $split, self::lotSource($referenceType), $referenceId);
                    $this->stocks->changeLotQuantity($lot->id(), $split->quantity);
                    $this->ledger->appendReceipt($product, $warehouseId, $split->quantity, $referenceType, $referenceId, $performedBy, $lot->id(), $lot->unitCost());
                    $received[] = self::lotLine($product, $lot, $split->quantity);
                }
            }
            if ($afterMovements !== null) {
                $afterMovements($received);
            }
            $this->audit?->append($performedBy, 'purchase-orders.receive', $referenceType, $referenceId, 'success', RequestOrigin::none(), ['movement_count' => count($movements), 'lots' => $received]);
        });
    }

    /**
     * Physical goods issue. With $releaseReservation (the sales-order path, D-12/ADR-018) the goods were reserved at
     * approval: after the lock each line must still be covered by both quantity and reserved_quantity, and one statement
     * lowers both. Without it (an unreserved issue) the line must fit the *available* quantity, so reserved stock of
     * other orders is never consumed. A sales order may be issued in several shipments (D-14, ADR-020): each call is one
     * shipment and $movements holds only the quantities shipped now, so the post-lock check compares each line with the
     * current reservation. $auditMetadata adds shipment details to the strict audit row.
     *
     * Lots (D-17, ADR-023): every line is allocated FEFO over the non-expired lots after all locks (expiry ascending,
     * no expiry last, then first receipt, then id); one Issue ledger row per lot. Expired lots are never issued: when a
     * reservation outgrew the sellable stock because lots expired after approval, the issue is refused with 422.
     *
     * @param list<StockMovement> $movements
     * @param array<string, scalar> $auditMetadata
     */
    public function issue(
        int $warehouseId,
        array $movements,
        int $performedBy,
        string $referenceType,
        int $referenceId,
        ?callable $afterMovements = null,
        bool $releaseReservation = false,
        array $auditMetadata = [],
    ): void {
        $this->assertCommonInput($warehouseId, $movements, $performedBy, $referenceType, $referenceId, 'Issue');
        usort($movements, static fn (StockMovement $a, StockMovement $b): int => $a->productId() <=> $b->productId());

        $this->transaction(function () use ($warehouseId, $movements, $performedBy, $referenceType, $referenceId, $afterMovements, $releaseReservation, $auditMetadata): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $warehouseId);
            }
            $lots = $this->lockLotsFor($warehouseId, array_map(static fn (StockMovement $movement): int => $movement->productId(), $movements));
            foreach ($movements as $movement) {
                $quantity = $this->stocks->quantity($movement->productId(), $warehouseId);
                $reserved = $this->stocks->reservedQuantity($movement->productId(), $warehouseId);
                $sellable = self::sellable($lots[$movement->productId()]);
                if ($quantity < $movement->quantity()) {
                    throw new InvalidArgumentException('Insufficient stock.');
                }
                if ($releaseReservation && $reserved < $movement->quantity()) {
                    throw new InvalidArgumentException('Insufficient stock: the reservation for this order is missing.');
                }
                if ($releaseReservation && $sellable < $movement->quantity()) {
                    throw new ValidationException('Only ' . $sellable . ' unit(s) of product #' . $movement->productId() . ' in warehouse #' . $warehouseId . ' are not expired; '
                        . ($quantity - $sellable) . ' unit(s) expired after the order was approved. Expired stock is never shipped: write off the expired lots and receive fresh stock, or close/cancel the remainder. Nothing was issued.');
                }
                if (!$releaseReservation && $sellable - $reserved < $movement->quantity()) {
                    throw new InvalidArgumentException('Insufficient available stock.');
                }
            }
            $shipped = [];
            foreach ($movements as $movement) {
                $product = $movement->productId();
                $allocation = self::allocateFefo($lots[$product], $movement->quantity(), false);
                if ($releaseReservation) {
                    $this->stocks->issueReserved($product, $warehouseId, $movement->quantity());
                } else {
                    $this->stocks->decrement($product, $warehouseId, $movement->quantity());
                }
                foreach ($allocation as [$lot, $units]) {
                    $this->stocks->changeLotQuantity($lot->id(), -$units);
                    $this->ledger->appendIssue($product, $warehouseId, $units, $referenceType, $referenceId, $performedBy, $lot->id(), $lot->unitCost());
                    $shipped[] = self::lotLine($product, $lot, $units);
                }
            }
            if ($afterMovements !== null) {
                $afterMovements($shipped);
            }
            $metadata = ['movement_count' => count($movements)] + $auditMetadata;
            if ($releaseReservation) {
                $metadata['reservation_released_units'] = $this->units($movements);
                $metadata['shipment'] = $this->lines($movements);
            }
            $metadata['lots'] = $shipped;
            $this->audit?->append($performedBy, 'sales-orders.issue', $referenceType, $referenceId, 'success', RequestOrigin::none(), $metadata);
        });
    }

    /**
     * Reserves stock for an order (D-12, ADR-018): lock the pairs in product order, require available ≥ quantity for
     * every line (otherwise 422 and nothing changes), then reserved += quantity, run $afterReserve (the order state
     * change) and append the strict audit row with the reserved lines. No ledger row: a reservation moves no goods.
     * Callers lock their source order first (ADR-004). Available excludes expired lots (D-17, ADR-023).
     *
     * @param list<StockMovement> $movements
     */
    public function reserve(int $warehouseId, array $movements, int $performedBy, string $referenceType, int $referenceId, callable $afterReserve, string $auditAction): void
    {
        $this->assertCommonInput($warehouseId, $movements, $performedBy, $referenceType, $referenceId, 'Reservation');
        $movements = $this->sortedDistinct($movements);
        $this->transaction(function () use ($warehouseId, $movements, $performedBy, $referenceType, $referenceId, $afterReserve, $auditAction): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $warehouseId);
            }
            $lots = $this->lockLotsFor($warehouseId, array_map(static fn (StockMovement $movement): int => $movement->productId(), $movements));
            $short = [];
            foreach ($movements as $movement) {
                $available = self::sellable($lots[$movement->productId()]) - $this->stocks->reservedQuantity($movement->productId(), $warehouseId);
                if ($available < $movement->quantity()) {
                    $short[] = 'product #' . $movement->productId() . ' needs ' . $movement->quantity() . ', available ' . max(0, $available);
                }
            }
            if ($short !== []) {
                throw new ValidationException('Insufficient available stock at the source warehouse (' . implode('; ', $short) . '). Expired lots do not count. Nothing was reserved.');
            }
            foreach ($movements as $movement) {
                $this->stocks->reserve($movement->productId(), $warehouseId, $movement->quantity());
            }
            $afterReserve();
            $this->audit?->append($performedBy, $auditAction, $referenceType, $referenceId, 'success', RequestOrigin::none(), [
                'warehouse_id' => $warehouseId,
                'reserved_units' => $this->units($movements),
                'reservation' => $this->lines($movements),
            ]);
        });
    }

    /**
     * Releases an order's reservation without moving goods (cancellation of an Approved order). An empty movement list
     * releases nothing but still runs $afterRelease and the audit in one transaction, so every cancellation is recorded
     * the same way. A missing reservation is an invariant breach and rolls everything back.
     *
     * @param list<StockMovement> $movements
     * @param array<string, scalar> $metadata
     */
    public function release(int $warehouseId, array $movements, int $performedBy, string $referenceType, int $referenceId, callable $afterRelease, string $auditAction, array $metadata = []): void
    {
        if ($movements !== []) {
            $this->assertCommonInput($warehouseId, $movements, $performedBy, $referenceType, $referenceId, 'Reservation');
        } elseif ($performedBy <= 0 || $referenceType === '' || $referenceId <= 0) {
            throw new InvalidArgumentException('Reference is required.');
        }
        $movements = $this->sortedDistinct($movements);
        $this->transaction(function () use ($warehouseId, $movements, $performedBy, $referenceType, $referenceId, $afterRelease, $auditAction, $metadata): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $warehouseId);
            }
            foreach ($movements as $movement) {
                if ($this->stocks->reservedQuantity($movement->productId(), $warehouseId) < $movement->quantity()) {
                    throw new \LogicException('Reservation is missing for product #' . $movement->productId() . '; nothing was released.');
                }
            }
            foreach ($movements as $movement) {
                $this->stocks->release($movement->productId(), $warehouseId, $movement->quantity());
            }
            $afterRelease();
            $metadata['reservation_released_units'] = $this->units($movements);
            if ($movements !== []) {
                $metadata['warehouse_id'] = $warehouseId;
            }
            $this->audit?->append($performedBy, $auditAction, $referenceType, $referenceId, 'success', RequestOrigin::none(), $metadata + ($movements === [] ? [] : ['reservation' => $this->lines($movements)]));
        });
    }

    /** @param list<StockMovement> $movements @return list<StockMovement> */
    private function sortedDistinct(array $movements): array
    {
        usort($movements, static fn (StockMovement $a, StockMovement $b): int => $a->productId() <=> $b->productId());
        $seen = [];
        foreach ($movements as $movement) {
            if (isset($seen[$movement->productId()])) {
                throw new InvalidArgumentException('Duplicate product lines are not allowed.');
            }
            $seen[$movement->productId()] = true;
        }

        return $movements;
    }

    /** @param list<StockMovement> $movements */
    private function units(array $movements): int
    {
        return array_sum(array_map(static fn (StockMovement $movement): int => $movement->quantity(), $movements));
    }

    /** @param list<StockMovement> $movements @return list<array{product_id: int, quantity: int}> */
    private function lines(array $movements): array
    {
        return array_map(static fn (StockMovement $movement): array => ['product_id' => $movement->productId(), 'quantity' => $movement->quantity()], $movements);
    }

    /**
     * Signed changes of adjustments, returns and expired write-offs (ADR-009, D-17/ADR-023). Locks every pair in
     * (product, warehouse) order, then each pair's lots; checks every count baseline (pair or lot) after the lock (409),
     * that the lot targets are valid, that results stay within 0..UINT32, that quantity never drops below the
     * reservation and that a decrease of non-expired stock never leaves less sellable stock than reserved. One ledger row
     * per lot.
     *
     * @param list<StockDelta> $deltas
     */
    public function adjust(array $deltas,int $actor,string $reference,int $id,callable $after): void
    {
        if($actor<1 || $id<1 || $reference==='' || $deltas===[]) { throw new InvalidArgumentException('Invalid adjustment.'); }
        $ledger=$this->adjustmentLedger();
        usort($deltas,static fn(StockDelta $a,StockDelta $b): int => [$a->product,$a->warehouse]<=>[$b->product,$b->warehouse]);
        $this->assertDistinctDeltas($deltas);
        $this->transaction(function() use($deltas,$actor,$reference,$id,$after,$ledger): void {
            $pairs=[];
            foreach($deltas as $delta) { $pairs[$delta->product.':'.$delta->warehouse]=[$delta->product,$delta->warehouse]; }
            foreach($pairs as [$product,$warehouse]) { $this->stocks->lockByProductWarehouse($product,$warehouse); }
            $lots=[];
            foreach($pairs as $key=>[$product,$warehouse]) { $lots[$key]=$this->lockedLots($product,$warehouse); }
            $today=$this->stocks->today();
            $plans=[];
            foreach($pairs as $key=>[$product,$warehouse]) {
                $pairDeltas=array_values(array_filter($deltas,static fn(StockDelta $delta): bool => $delta->product===$product && $delta->warehouse===$warehouse));
                $plans[$key]=$this->planPair($product,$warehouse,$pairDeltas,$lots[$key],$reference,$id,$today);
            }
            $movements=[];
            foreach($pairs as $key=>[$product,$warehouse]) {
                $net=array_sum(array_map(static fn(array $step): int => $step[1],$plans[$key]));
                if($net>0) { $this->stocks->increment($product,$warehouse,$net); } elseif($net<0) { $this->stocks->decrement($product,$warehouse,-$net); }
                foreach($plans[$key] as [$target,$units]) {
                    $lot=$target instanceof LotSplit?$this->resolveLot($product,$warehouse,$lots[$key],$target,self::lotSource($reference),$id):$target;
                    $this->stocks->changeLotQuantity($lot->id(),$units);
                    $ledger->appendAdjustment($product,$warehouse,$units,$reference,$id,$actor,$lot->id(),$lot->unitCost());
                    $movements[]=self::lotLine($product,$lot,$units)+['warehouse_id'=>$warehouse];
                }
            }
            $after($movements);
            $this->audit?->append($actor,'inventory-operations.post',$reference,$id,'success',RequestOrigin::none(),['movement_count'=>count($movements),'lots'=>$movements]);
        });
    }

    /**
     * Post-lock plan for one pair: list of [lot or new-lot split, signed units]. Checks baselines, lot targets, bounds and
     * the reservation guards.
     *
     * @param list<StockDelta> $deltas
     * @param list<StockLot> $lots
     * @return list<array{0: StockLot|LotSplit, 1: int}>
     */
    private function planPair(int $product,int $warehouse,array $deltas,array $lots,string $reference,int $id,string $today): array
    {
        $current=$this->stocks->quantity($product,$warehouse);
        $reserved=$this->stocks->reservedQuantity($product,$warehouse);
        $byId=[];foreach($lots as $lot) { $byId[$lot->id()]=$lot; }
        $plan=[];$net=0;$sellableChange=0;$decreasesSellable=false;
        foreach($deltas as $delta) {
            $target=$delta->lot??LotTarget::fefo();
            if($target->mode==='lot') {
                $lot=$byId[(int)$target->lotId]??throw new ValidationException('Lot #'.(int)$target->lotId.' does not belong to product #'.$product.' in warehouse #'.$warehouse.'.');
                if($delta->baseline!==null && $lot->quantity()!==$delta->baseline) { throw new \App\Exception\HttpException(409,'Stock changed since the count. Cancel this proposal and recount.'); }
                if($target->requireExpired && !$lot->isExpired()) { throw new \App\Exception\HttpException(409,'Lot '.$lot->code().' is no longer expired; only expired lots can be written off. Cancel this proposal.'); }
                if($delta->delta<0 && $lot->quantity()<-$delta->delta) { throw new ValidationException('Lot '.$lot->code().' of product #'.$product.' has only '.$lot->quantity().' unit(s) left.'); }
                $plan[]=[$lot,$delta->delta];
                if(!$lot->isExpired()) { $sellableChange+=$delta->delta;$decreasesSellable=$decreasesSellable||$delta->delta<0; }
            } elseif($target->mode==='create' || $target->mode==='legacy' || $delta->delta>0) {
                if($delta->baseline!==null && $target->mode==='fefo' && $current!==$delta->baseline) { throw new \App\Exception\HttpException(409,'Stock changed since the count. Cancel this proposal and recount.'); }
                if($delta->delta<=0) {
                    if($target->mode!=='legacy') { throw new InvalidArgumentException('A new lot needs a positive quantity.'); }
                    // Return of a pre-migration receipt: the LEGACY lot first, the rest FEFO over the other lots.
                    if($current+$delta->delta<0) { throw new InvalidArgumentException('Insufficient stock or quantity overflow.'); }
                    $legacy=$this->legacyLot($lots);
                    $ordered=$legacy===null?$lots:[$legacy,...array_values(array_filter(StockLot::fefo($lots),static fn(StockLot $lot): bool => $lot->id()!==$legacy->id()))];
                    $left=-$delta->delta;
                    foreach($ordered as $lot) {
                        if($left===0) { break; }
                        $units=min($left,$lot->quantity());
                        if($units<=0) { continue; }
                        $plan[]=[$lot,-$units];$left-=$units;
                        if(!$lot->isExpired()) { $sellableChange-=$units;$decreasesSellable=true; }
                    }
                    if($left>0) { throw new \LogicException('Lots do not cover the pair quantity (Σ lots ≠ stock); nothing was changed.'); }
                } else {
                    $split=match($target->mode) {
                        'create'=>($target->split??throw new InvalidArgumentException('Lot is required.'))->withQuantity($delta->delta),
                        'legacy'=>new LotSplit(StockLot::LEGACY,null,$delta->delta,null),
                        default=>new LotSplit($reference.'-'.$id,null,$delta->delta,null),
                    };
                    $existing=$target->mode==='legacy'?$this->legacyLot($lots):null;
                    if($existing!==null) { $plan[]=[$existing,$delta->delta]; }
                    else {
                        $this->assertLotPolicy($product,$split,$today,false);
                        $split=new LotSplit($split->code,$split->expiryDate,$split->quantity,self::money($split->unitCost??$this->stocks->purchaseCost($product)));
                        $plan[]=[$split,$delta->delta];
                    }
                    if($split->expiryDate===null || $split->expiryDate>=$today) { $sellableChange+=$delta->delta; }
                }
            } else {
                // Pair-level count shortfall: taken over all lots in FEFO order (expired lots expire first).
                if($delta->baseline!==null && $current!==$delta->baseline) { throw new \App\Exception\HttpException(409,'Stock changed since the count. Cancel this proposal and recount.'); }
                if($current+$delta->delta<0) { throw new InvalidArgumentException('Insufficient stock or quantity overflow.'); }
                foreach(self::allocateFefo($lots,-$delta->delta,true) as [$lot,$units]) {
                    $plan[]=[$lot,-$units];
                    if(!$lot->isExpired()) { $sellableChange-=$units;$decreasesSellable=true; }
                }
            }
            $net+=$delta->delta;
        }
        if($current+$net<0 || $current+$net>4294967295) { throw new InvalidArgumentException('Insufficient stock or quantity overflow.'); }
        // D-12/ADR-018: a decrease may never cut into stock reserved for Approved sales orders.
        if($net<0 && $current+$net<$reserved) { throw new ValidationException('Insufficient available stock: '.$reserved.' unit(s) of product #'.$product.' in warehouse #'.$warehouse.' are reserved for approved sales orders, only '.max(0,$current-$reserved).' available.'); }
        // D-17/ADR-023: nor may it leave less non-expired (sellable) stock than is reserved.
        $sellable=self::sellable($lots);
        if($decreasesSellable && $sellableChange<0 && $sellable+$sellableChange<$reserved) { throw new ValidationException('Insufficient available stock: '.$reserved.' unit(s) of product #'.$product.' in warehouse #'.$warehouse.' are reserved for approved sales orders and only '.max(0,$sellable-$reserved).' non-expired unit(s) are available.'); }
        return $plan;
    }

    /** @param list<StockLot> $lots */
    private function legacyLot(array $lots): ?StockLot
    {
        $legacy=array_values(array_filter($lots,static fn(StockLot $lot): bool => $lot->isLegacy()));
        usort($legacy,static fn(StockLot $a,StockLot $b): int => $a->sequence()<=>$b->sequence());
        return $legacy[0]??null;
    }

    /**
     * Transfer dispatch (D-15, ADR-021): the goods leave the source warehouse and are in transit. Callers lock the
     * transfer document first (ADR-004); here the source rows are locked in product order, every line must fit the
     * *available* quantity after the lock (reservations of approved sales orders are never consumed, ADR-018), then
     * quantity −= n with one negative Adjustment ledger row per lot (reference TRANSFER). Lots are picked FEFO over the
     * non-expired lots (D-17, ADR-023); $afterDispatch receives the picked lots and records the document state; the
     * strict audit row `inventory-operations.dispatch` commits with it.
     *
     * @param list<StockMovement> $movements
     * @param array<string, scalar> $metadata
     */
    public function dispatchTransfer(int $sourceWarehouseId, array $movements, int $performedBy, int $transferId, callable $afterDispatch, array $metadata = []): void
    {
        $this->assertCommonInput($sourceWarehouseId, $movements, $performedBy, 'TRANSFER', $transferId, 'Dispatch');
        $ledger = $this->adjustmentLedger();
        $movements = $this->sortedDistinct($movements);
        $this->transaction(function () use ($sourceWarehouseId, $movements, $performedBy, $transferId, $afterDispatch, $metadata, $ledger): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $sourceWarehouseId);
            }
            $lots = $this->lockLotsFor($sourceWarehouseId, array_map(static fn (StockMovement $movement): int => $movement->productId(), $movements));
            $short = [];
            foreach ($movements as $movement) {
                $available = self::sellable($lots[$movement->productId()]) - $this->stocks->reservedQuantity($movement->productId(), $sourceWarehouseId);
                if ($available < $movement->quantity()) {
                    $short[] = 'product #' . $movement->productId() . ' needs ' . $movement->quantity() . ', available ' . max(0, $available);
                }
            }
            if ($short !== []) {
                throw new ValidationException('Insufficient available stock at the source warehouse (' . implode('; ', $short) . '); stock reserved for approved sales orders and expired lots are never dispatched. Nothing was dispatched.');
            }
            $picked = [];
            foreach ($movements as $movement) {
                $product = $movement->productId();
                $this->stocks->decrement($product, $sourceWarehouseId, $movement->quantity());
                foreach (self::allocateFefo($lots[$product], $movement->quantity(), false) as [$lot, $units]) {
                    $this->stocks->changeLotQuantity($lot->id(), -$units);
                    $ledger->appendAdjustment($product, $sourceWarehouseId, -$units, 'TRANSFER', $transferId, $performedBy, $lot->id(), $lot->unitCost());
                    $picked[] = self::lotLine($product, $lot, $units);
                }
            }
            $afterDispatch($picked);
            $this->audit?->append($performedBy, 'inventory-operations.dispatch', 'TRANSFER', $transferId, 'success', RequestOrigin::none(), [
                'warehouse_id' => $sourceWarehouseId,
                'movement_count' => count($movements),
                'dispatched_units' => $this->units($movements),
                'dispatch' => $this->lines($movements),
                'lots' => $picked,
            ] + $metadata);
        });
    }

    /**
     * Transfer receipt (D-15, ADR-021): only the good units enter the destination warehouse (quantity += n, one positive
     * Adjustment row per lot, reference TRANSFER). Each movement's splits carry the source lot's code, expiry and unit cost
     * (D-17, ADR-023): the destination lot with the same identity is merged or created. Damaged and short units never
     * enter sellable stock and have no ledger row; the document lines and the audit metadata keep them. $movements may be
     * empty (nothing arrived in good condition); the document state and the strict audit row
     * `inventory-operations.receive` still commit together. $afterReceive receives [product id => destination lot id per
     * split, in split order].
     *
     * @param list<StockMovement> $movements good units per product
     * @param array<string, mixed> $metadata
     */
    public function receiveTransfer(int $destinationWarehouseId, array $movements, int $performedBy, int $transferId, callable $afterReceive, array $metadata = []): void
    {
        if ($movements !== []) {
            $this->assertCommonInput($destinationWarehouseId, $movements, $performedBy, 'TRANSFER', $transferId, 'Receipt');
            $this->assertSplits($movements);
        } elseif ($destinationWarehouseId <= 0 || $performedBy <= 0 || $transferId <= 0) {
            throw new InvalidArgumentException('Reference is required.');
        }
        $ledger = $this->adjustmentLedger();
        $movements = $this->sortedDistinct($movements);
        $this->transaction(function () use ($destinationWarehouseId, $movements, $performedBy, $transferId, $afterReceive, $metadata, $ledger): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $destinationWarehouseId);
            }
            $lots = $this->lockLotsFor($destinationWarehouseId, array_map(static fn (StockMovement $movement): int => $movement->productId(), $movements));
            foreach ($movements as $movement) {
                if ($this->stocks->quantity($movement->productId(), $destinationWarehouseId) + $movement->quantity() > 4294967295) {
                    throw new InvalidArgumentException('Quantity overflow.');
                }
            }
            $destinationLots = [];
            $received = [];
            foreach ($movements as $movement) {
                $product = $movement->productId();
                $this->stocks->increment($product, $destinationWarehouseId, $movement->quantity());
                foreach ($this->splitsOf($movement, 'TRANSFER', $transferId) as $split) {
                    $lot = $split->code === StockLot::LEGACY && $split->expiryDate === null ? ($this->legacyLot($lots[$product]) ?? $this->resolveLot($product, $destinationWarehouseId, $lots[$product], $split, 'TRANSFER', $transferId))
                        : $this->resolveLot($product, $destinationWarehouseId, $lots[$product], $split, 'TRANSFER', $transferId);
                    $this->stocks->changeLotQuantity($lot->id(), $split->quantity);
                    $ledger->appendAdjustment($product, $destinationWarehouseId, $split->quantity, 'TRANSFER', $transferId, $performedBy, $lot->id(), $lot->unitCost());
                    $destinationLots[$product][] = $lot->id();
                    $received[] = self::lotLine($product, $lot, $split->quantity);
                }
            }
            $afterReceive($destinationLots);
            $this->audit?->append($performedBy, 'inventory-operations.receive', 'TRANSFER', $transferId, 'success', RequestOrigin::none(), [
                'warehouse_id' => $destinationWarehouseId,
                'movement_count' => count($movements),
            ] + $metadata + ['lots' => $received]);
        });
    }

    // ---- Stock count sessions (D-18, ADR-024) --------------------------------------------------------------------

    /**
     * Locks the pairs of $productIds in one warehouse (product order) and then their lots (ADR-023 lock order), checks
     * Σ lots = stock per pair, and runs $work with the locked lots [product id => list<StockLot>] inside the transaction.
     * Moves nothing. Used by a count to take its snapshot and to record counts: while the pairs are locked no movement of
     * these lots is in flight, so every ledger row of them is either committed and visible or will be written after this
     * transaction with a higher id than any id read here (the ledger watermark of ADR-024).
     *
     * Callers must not run a non-locking read in the same transaction before this call: InnoDB fixes the REPEATABLE READ
     * view at the first consistent read, and the ledger reads in $work must see every row committed before the locks.
     *
     * @template T
     * @param list<int> $productIds
     * @param callable(array<int, list<StockLot>>): T $work
     * @return T
     */
    public function lockForCount(int $warehouseId, array $productIds, callable $work): mixed
    {
        if ($warehouseId <= 0 || $productIds === []) {
            throw new InvalidArgumentException('Warehouse and products are required.');
        }
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds);

        return $this->transaction(function () use ($warehouseId, $productIds, $work): mixed {
            foreach ($productIds as $productId) {
                $this->stocks->lockByProductWarehouse($productId, $warehouseId);
            }

            return $work($this->lockLotsFor($warehouseId, $productIds));
        });
    }

    /**
     * Posts approved count lines (D-18, ADR-024). Locks the pairs in product order, then their lots; then checks every
     * line before anything changes and refuses the whole posting with 422 listing *all* conflicting lines when a delta
     * would make a lot negative, overflow, leave the pair below its reservation (ADR-018) or leave less non-expired stock
     * than reserved (ADR-023). Otherwise each delta is applied to the lot's *current* quantity (never "set to counted"):
     * one Adjustment ledger row per lot with reference STOCK_COUNT, found lots are created (cost = current purchase price
     * per base, source STOCK_COUNT), the pair moves by the net delta, $after receives per line the lot, delta, unit cost,
     * pair quantity after and the reservation, and the strict audit row `stock-counts.post` commits with it.
     * $afterLock runs right after the locks (the count re-verifies its lines there and may refuse).
     *
     * @param list<CountPosting> $postings
     * @param callable(array<int, array{lot_id: int|null, delta: int, unit_cost: string|null, pair_quantity_after: int, pair_reserved: int}>): void $after
     * @param (callable(array<int, list<StockLot>>): void)|null $afterLock
     */
    public function postCount(int $warehouseId, array $postings, int $actor, int $countId, callable $after, ?callable $afterLock = null): void
    {
        if ($warehouseId <= 0 || $actor <= 0 || $countId <= 0 || $postings === []) {
            throw new InvalidArgumentException('Invalid count posting.');
        }
        $ledger = $this->adjustmentLedger();
        usort($postings, static fn (CountPosting $a, CountPosting $b): int => [$a->productId, $a->lineId] <=> [$b->productId, $b->lineId]);
        $productIds = array_values(array_unique(array_map(static fn (CountPosting $posting): int => $posting->productId, $postings)));

        $this->transaction(function () use ($warehouseId, $postings, $actor, $countId, $after, $afterLock, $ledger, $productIds): void {
            foreach ($productIds as $productId) {
                $this->stocks->lockByProductWarehouse($productId, $warehouseId);
            }
            $lots = $this->lockLotsFor($warehouseId, $productIds);
            if ($afterLock !== null) {
                $afterLock($lots);
            }
            $today = $this->stocks->today();
            $conflicts = [];
            $pairs = [];
            foreach ($productIds as $productId) {
                $pairs[$productId] = $this->planCountPair($productId, $warehouseId, array_values(array_filter($postings, static fn (CountPosting $posting): bool => $posting->productId === $productId)), $lots[$productId], $today, $conflicts);
            }
            if ($conflicts !== []) {
                throw new ValidationException('Posting refused; nothing was changed. Conflicting lines: ' . implode('; ', $conflicts) . '.');
            }
            $posted = [];
            $movements = [];
            foreach ($productIds as $productId) {
                ['net' => $net, 'current' => $current, 'reserved' => $reserved] = $pairs[$productId];
                if ($net > 0) {
                    $this->stocks->increment($productId, $warehouseId, $net);
                } elseif ($net < 0) {
                    $this->stocks->decrement($productId, $warehouseId, -$net);
                }
                $byId = [];
                foreach ($lots[$productId] as $lot) {
                    $byId[$lot->id()] = $lot;
                }
                foreach ($postings as $posting) {
                    if ($posting->productId !== $productId) {
                        continue;
                    }
                    $lot = $posting->lotId !== null ? ($byId[$posting->lotId] ?? throw new \LogicException('Lot #' . $posting->lotId . ' is not locked.')) : null;
                    if ($posting->delta !== 0) {
                        if ($lot === null) {
                            $split = $posting->newLot ?? throw new \LogicException('Found lot is missing.');
                            $lot = $this->resolveLot($productId, $warehouseId, $lots[$productId], new LotSplit($split->code, $split->expiryDate, $posting->delta, self::money($this->stocks->purchaseCost($productId))), 'STOCK_COUNT', $countId);
                        }
                        $this->stocks->changeLotQuantity($lot->id(), $posting->delta);
                        $ledger->appendAdjustment($productId, $warehouseId, $posting->delta, 'STOCK_COUNT', $countId, $actor, $lot->id(), $lot->unitCost());
                        $movements[] = self::lotLine($productId, $lot, $posting->delta) + ['line_id' => $posting->lineId];
                    }
                    $posted[$posting->lineId] = [
                        'lot_id' => $lot?->id(),
                        'delta' => $posting->delta,
                        'unit_cost' => $lot?->unitCost(),
                        'pair_quantity_after' => $current + $net,
                        'pair_reserved' => $reserved,
                    ];
                }
            }
            $after($posted);
            $this->audit?->append($actor, 'stock-counts.post', 'STOCK_COUNT', $countId, 'success', RequestOrigin::none(), [
                'warehouse_id' => $warehouseId,
                'line_count' => count($postings),
                'movement_count' => count($movements),
                'net_units' => array_sum(array_map(static fn (CountPosting $posting): int => $posting->delta, $postings)),
                'lots' => $movements,
            ]);
        });
    }

    /**
     * Post-lock checks of one pair of a count posting; appends a message per conflicting line to $conflicts.
     *
     * @param list<CountPosting> $postings
     * @param list<StockLot> $lots
     * @param list<string> $conflicts
     * @return array{net: int, current: int, reserved: int}
     */
    private function planCountPair(int $productId, int $warehouseId, array $postings, array $lots, string $today, array &$conflicts): array
    {
        $current = $this->stocks->quantity($productId, $warehouseId);
        $reserved = $this->stocks->reservedQuantity($productId, $warehouseId);
        $byId = [];
        foreach ($lots as $lot) {
            $byId[$lot->id()] = $lot;
        }
        $net = 0;
        $sellableChange = 0;
        $decreasesSellable = false;
        $labels = [];
        foreach ($postings as $posting) {
            if ($posting->delta === 0) {
                continue;
            }
            $labels[] = $posting->label;
            if ($posting->lotId !== null) {
                $lot = $byId[$posting->lotId] ?? null;
                if ($lot === null) {
                    $conflicts[] = $posting->label . ': the lot no longer belongs to this product and warehouse';
                    continue;
                }
                if ($lot->quantity() + $posting->delta < 0) {
                    $conflicts[] = $posting->label . ': variance ' . $posting->delta . ' would make lot ' . $lot->code() . ' negative (it now holds ' . $lot->quantity() . ')';
                }
                if (!$lot->isExpired()) {
                    $sellableChange += $posting->delta;
                    $decreasesSellable = $decreasesSellable || $posting->delta < 0;
                }
            } else {
                $split = $posting->newLot ?? throw new \LogicException('Found lot is missing.');
                try {
                    $this->assertLotPolicy($productId, $split, $today, false);
                } catch (ValidationException $exception) {
                    $conflicts[] = $posting->label . ': ' . $exception->getMessage();
                }
                if ($split->expiryDate === null || $split->expiryDate >= $today) {
                    $sellableChange += $posting->delta;
                }
            }
            $net += $posting->delta;
        }
        $after = $current + $net;
        $named = implode(', ', $labels);
        if ($after > 4294967295) {
            $conflicts[] = $named . ': quantity overflow';
        } elseif ($net < 0 && $after < $reserved) {
            // D-12/ADR-018: a count may never cut into stock reserved for Approved sales orders.
            $conflicts[] = $named . ': product #' . $productId . ' would drop to ' . $after . ' unit(s), below the ' . $reserved . ' unit(s) reserved for approved sales orders';
        } elseif ($decreasesSellable && $sellableChange < 0 && self::sellable($lots) + $sellableChange < $reserved) {
            // D-17/ADR-023: nor leave less non-expired stock than reserved.
            $conflicts[] = $named . ': only ' . max(0, self::sellable($lots) + $sellableChange) . ' non-expired unit(s) of product #' . $productId . ' would remain for ' . $reserved . ' reserved unit(s)';
        }

        return ['net' => $net, 'current' => $current, 'reserved' => $reserved];
    }

    private function adjustmentLedger(): \App\Repository\Contract\AdjustmentLedgerRepositoryInterface
    {
        if (!$this->ledger instanceof \App\Repository\Contract\AdjustmentLedgerRepositoryInterface) { throw new \LogicException('Adjustment ledger repository is required.'); }

        return $this->ledger;
    }

    /** @param list<StockDelta> $deltas sorted by product and warehouse */
    private function assertDistinctDeltas(array $deltas): void
    {
        $seen=[];$pairLevel=[];
        foreach($deltas as $delta) {
            $pair=$delta->product.':'.$delta->warehouse;
            $target=$delta->lot;
            $key=$pair.':'.($target===null?'pair':$target->mode.':'.($target->lotId??($target->split===null?'':strtoupper($target->split->code).'|'.($target->split->expiryDate??''))));
            $isPair=$target===null || $target->mode==='fefo';
            if($delta->product<1 || $delta->warehouse<1 || $delta->delta===0 || abs($delta->delta)>4294967295 || isset($seen[$key]) || ($isPair && isset($seen[$pair])) || (!$isPair && isset($pairLevel[$pair]))) { throw new InvalidArgumentException('Invalid or duplicate stock delta.'); }
            $seen[$key]=true;$seen[$pair]=$seen[$pair]??false;
            if($isPair) { $pairLevel[$pair]=true; }
        }
    }

    // ---- Lots (D-17, ADR-023) ----------------------------------------------------------------------------------

    /**
     * Locks the lots of each pair (the pairs are already locked; ADR-023 lock order) in product order.
     *
     * @param list<int> $productIds
     * @return array<int, list<StockLot>> product id => lots in FEFO order
     */
    private function lockLotsFor(int $warehouseId, array $productIds): array
    {
        $lots = [];
        foreach ($productIds as $productId) {
            $lots[$productId] = $this->lockedLots($productId, $warehouseId);
        }

        return $lots;
    }

    /**
     * Locks the lots of one locked pair and checks Σ lots = pair quantity (ADR-023 invariant) before anything changes, so a
     * breach (for example stock written outside StockService) can never be compounded by an allocation.
     *
     * @return list<StockLot>
     */
    private function lockedLots(int $productId, int $warehouseId): array
    {
        $lots = $this->stocks->lockLots($productId, $warehouseId);
        $sum = array_sum(array_map(static fn (StockLot $lot): int => $lot->quantity(), $lots));
        if ($sum !== $this->stocks->quantity($productId, $warehouseId)) {
            throw new \LogicException('Lots of product #' . $productId . ' in warehouse #' . $warehouseId . ' do not add up to its stock (Σ lots ≠ quantity); nothing was changed.');
        }

        return $lots;
    }

    /** @param list<StockLot> $lots */
    private static function sellable(array $lots): int
    {
        return array_sum(array_map(static fn (StockLot $lot): int => $lot->isExpired() ? 0 : $lot->quantity(), $lots));
    }

    /**
     * FEFO allocation of $quantity over lots with stock (non-expired only unless $includeExpired). The lots are already in
     * FEFO order. Callers have checked the total; a shortfall here is an invariant breach.
     *
     * @param list<StockLot> $lots
     * @return list<array{0: StockLot, 1: int}>
     */
    private static function allocateFefo(array $lots, int $quantity, bool $includeExpired): array
    {
        $allocation = [];
        $left = $quantity;
        foreach (StockLot::fefo($lots) as $lot) {
            if ($left === 0) {
                break;
            }
            if ($lot->quantity() <= 0 || (!$includeExpired && $lot->isExpired())) {
                continue;
            }
            $units = min($left, $lot->quantity());
            $allocation[] = [$lot, $units];
            $left -= $units;
        }
        if ($left > 0) {
            throw new \LogicException('Lots do not cover the pair quantity (Σ lots ≠ stock); nothing was changed.');
        }

        return $allocation;
    }

    /**
     * Existing lot with the split's identity, else a new one (added to $lots so a later split of the same call finds it).
     *
     * @param list<StockLot> $lots
     */
    private function resolveLot(int $productId, int $warehouseId, array &$lots, LotSplit $split, string $sourceType, ?int $sourceId): StockLot
    {
        $cost = self::money($split->unitCost ?? $this->stocks->purchaseCost($productId));
        foreach ($lots as $lot) {
            if ($lot->matches($split->code, $split->expiryDate, $cost)) {
                return $lot;
            }
        }
        $lot = $this->stocks->createLot($productId, $warehouseId, $split->code, $split->expiryDate, $cost, $sourceType, $sourceId);
        $lots[] = $lot;

        return $lot;
    }

    /** Expiry policy for a new lot: tracked products need an expiry (LEGACY excepted); a receipt refuses an expired date. */
    private function assertLotPolicy(int $productId, LotSplit $split, string $today, bool $refuseExpired): void
    {
        if ($split->expiryDate === null && $split->code !== StockLot::LEGACY && $this->stocks->tracksExpiry($productId)) {
            throw new ValidationException('Product #' . $productId . ' tracks expiry: enter the expiry date of lot ' . $split->code . '.');
        }
        if ($refuseExpired && $split->expiryDate !== null && $split->expiryDate < $today) {
            throw new ValidationException('Lot ' . $split->code . ' expired on ' . $split->expiryDate . '; expired goods cannot be received into stock.');
        }
    }

    /** @return list<LotSplit> */
    private function splitsOf(StockMovement $movement, string $referenceType, int $referenceId): array
    {
        if ($movement->lots() !== []) {
            return $movement->lots();
        }

        return [new LotSplit($movement->defaultLotCode() ?? ($referenceType . '-' . $referenceId), null, $movement->quantity(), $movement->defaultUnitCost())];
    }

    /** @param list<StockMovement> $movements */
    private function assertSplits(array $movements): void
    {
        foreach ($movements as $movement) {
            if ($movement->lots() === []) {
                continue;
            }
            $sum = 0;
            $seen = [];
            foreach ($movement->lots() as $split) {
                $key = strtoupper($split->code) . '|' . ($split->expiryDate ?? '') . '|' . ($split->unitCost ?? '');
                if ($split->quantity <= 0 || trim($split->code) === '' || isset($seen[$key])) {
                    throw new InvalidArgumentException('Lot quantities must be positive and each lot may appear once per line.');
                }
                $seen[$key] = true;
                $sum += $split->quantity;
            }
            if ($sum !== $movement->quantity()) {
                throw new ValidationException('Lot quantities must add up to the received quantity of product #' . $movement->productId() . '.');
            }
        }
    }

    private static function lotSource(string $referenceType): string
    {
        return in_array($referenceType, ['PO', 'TRANSFER', 'ADJUSTMENT', 'CUSTOMER_RETURN', 'STOCK_COUNT'], true) ? $referenceType : 'RECEIPT';
    }

    private static function money(string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /** @return array{product_id: int, lot_id: int, lot_code: string, expiry_date: string|null, unit_cost: string, quantity: int} */
    private static function lotLine(int $productId, StockLot $lot, int $units): array
    {
        return ['product_id' => $productId, 'lot_id' => $lot->id(), 'lot_code' => $lot->code(), 'expiry_date' => $lot->expiryDate(), 'unit_cost' => $lot->unitCost(), 'quantity' => $units];
    }

    /**
     * @param list<StockMovement> $movements
     */
    private function assertCommonInput(int $warehouseId, array $movements, int $performedBy, string $referenceType, int $referenceId, string $movementType): void
    {
        if ($warehouseId <= 0) {
            throw new InvalidArgumentException('Warehouse is required.');
        }
        if ($performedBy <= 0) {
            throw new InvalidArgumentException('Performer is required.');
        }
        if ($referenceType === '' || $referenceId <= 0) {
            throw new InvalidArgumentException('Reference is required.');
        }
        if ($movements === []) {
            throw new InvalidArgumentException('At least one stock movement is required.');
        }
        foreach ($movements as $movement) {
            if ($movement->productId() <= 0) {
                throw new InvalidArgumentException('Product is required.');
            }
            if ($movement->quantity() <= 0) {
                throw new InvalidArgumentException("{$movementType} quantity must be positive.");
            }
        }
    }
}
