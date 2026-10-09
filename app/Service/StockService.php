<?php

declare(strict_types=1);

namespace App\Service;

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

        usort(
            $movements,
            static fn (StockMovement $a, StockMovement $b): int => $a->productId() <=> $b->productId(),
        );

        $this->transaction(function () use ($warehouseId, $movements, $performedBy, $referenceType, $referenceId, $afterMovements): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $warehouseId);
            }
            foreach ($movements as $movement) {
                $this->stocks->increment($movement->productId(), $warehouseId, $movement->quantity());
                $this->ledger->appendReceipt(
                    $movement->productId(),
                    $warehouseId,
                    $movement->quantity(),
                    $referenceType,
                    $referenceId,
                    $performedBy,
                );
            }
            if ($afterMovements !== null) {
                $afterMovements();
            }
            $this->audit?->append($performedBy, 'purchase-orders.receive', $referenceType, $referenceId, 'success', RequestOrigin::none(), ['movement_count' => count($movements)]);
        });
    }

    /**
     * @param list<StockMovement> $movements
     */
    public function issue(
        int $warehouseId,
        array $movements,
        int $performedBy,
        string $referenceType,
        int $referenceId,
        ?callable $afterMovements = null,
    ): void {
        $this->assertCommonInput($warehouseId, $movements, $performedBy, $referenceType, $referenceId, 'Issue');
        usort($movements, static fn (StockMovement $a, StockMovement $b): int => $a->productId() <=> $b->productId());

        $this->transaction(function () use ($warehouseId, $movements, $performedBy, $referenceType, $referenceId, $afterMovements): void {
            foreach ($movements as $movement) {
                $this->stocks->lockByProductWarehouse($movement->productId(), $warehouseId);
            }
            foreach ($movements as $movement) {
                if ($this->stocks->quantity($movement->productId(), $warehouseId) < $movement->quantity()) {
                    throw new InvalidArgumentException('Insufficient stock.');
                }
            }
            foreach ($movements as $movement) {
                $this->stocks->decrement($movement->productId(), $warehouseId, $movement->quantity());
                $this->ledger->appendIssue(
                    $movement->productId(),
                    $warehouseId,
                    $movement->quantity(),
                    $referenceType,
                    $referenceId,
                    $performedBy,
                );
            }
            if ($afterMovements !== null) {
                $afterMovements();
            }
            $this->audit?->append($performedBy, 'sales-orders.issue', $referenceType, $referenceId, 'success', RequestOrigin::none(), ['movement_count' => count($movements)]);
        });
    }

    /** @param list<StockDelta> $deltas */
    public function adjust(array $deltas,int $actor,string $reference,int $id,callable $after): void
    {
        if($actor<1 || $id<1 || $reference==='' || $deltas===[]) { throw new InvalidArgumentException('Invalid adjustment.'); }
        if(!$this->ledger instanceof \App\Repository\Contract\AdjustmentLedgerRepositoryInterface) { throw new \LogicException('Adjustment ledger repository is required.'); }
        usort($deltas,static fn(StockDelta $a,StockDelta $b): int => [$a->product,$a->warehouse]<=>[$b->product,$b->warehouse]);
        $this->assertDistinctDeltas($deltas);
        $ledger=$this->ledger;
        $this->transaction(function() use($deltas,$actor,$reference,$id,$after,$ledger): void {
            foreach($deltas as $delta) { $this->stocks->lockByProductWarehouse($delta->product,$delta->warehouse); }
            foreach($deltas as $delta) { $this->assertDeltaFitsLockedStock($delta); }
            foreach($deltas as $delta) {
                if($delta->delta>0) { $this->stocks->increment($delta->product,$delta->warehouse,$delta->delta); }
                else { $this->stocks->decrement($delta->product,$delta->warehouse,-$delta->delta); }
                $ledger->appendAdjustment($delta->product,$delta->warehouse,$delta->delta,$reference,$id,$actor);
            }
            $after();
            $this->audit?->append($actor,'inventory-operations.post',$reference,$id,'success',RequestOrigin::none(),['movement_count'=>count($deltas)]);
        });
    }

    /** @param list<StockDelta> $deltas sorted by product and warehouse */
    private function assertDistinctDeltas(array $deltas): void
    {
        $seen=[];
        foreach($deltas as $delta) {
            $key=$delta->product.':'.$delta->warehouse;
            if($delta->product<1 || $delta->warehouse<1 || $delta->delta===0 || abs($delta->delta)>4294967295 || isset($seen[$key])) { throw new InvalidArgumentException('Invalid or duplicate stock delta.'); }
            $seen[$key]=true;
        }
    }

    /** Checked after the row lock: the count baseline still holds and the result stays within 0..UINT32. */
    private function assertDeltaFitsLockedStock(StockDelta $delta): void
    {
        $current=$this->stocks->quantity($delta->product,$delta->warehouse);
        if($delta->baseline!==null && $current!==$delta->baseline) { throw new \App\Exception\HttpException(409,'Stock changed since the count. Cancel this proposal and recount.'); }
        if($current+$delta->delta<0 || $current+$delta->delta>4294967295) { throw new InvalidArgumentException('Insufficient stock or quantity overflow.'); }
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
