<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\StockLedgerRepositoryInterface;
use App\Repository\Contract\StockRepositoryInterface;
use InvalidArgumentException;
use Throwable;

final class StockService
{
    public function __construct(
        private readonly StockRepositoryInterface $stocks,
        private readonly StockLedgerRepositoryInterface $ledger,
    ) {
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
                throw new InvalidArgumentException('Receipt quantity must be positive.');
            }
        }

        usort(
            $movements,
            static fn (StockMovement $a, StockMovement $b): int => $a->productId() <=> $b->productId(),
        );

        $this->stocks->beginTransaction();
        try {
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
            $this->stocks->commit();
        } catch (Throwable $exception) {
            $this->stocks->rollBack();
            throw $exception;
        }
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

        $this->stocks->beginTransaction();
        try {
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
            $this->stocks->commit();
        } catch (Throwable $exception) {
            $this->stocks->rollBack();
            throw $exception;
        }
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
