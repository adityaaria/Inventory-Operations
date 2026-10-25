<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\StockLot;

/**
 * Current balance per product/warehouse pair. `quantity` is physical stock (= Σ ledger); `reserved_quantity` is the part
 * committed to Approved sales orders (D-12, ADR-018). Available = quantity − expired − reserved (D-17, ADR-023). Every
 * write runs inside the StockService transaction after lockByProductWarehouse(); the database CHECK keeps reserved ≤
 * quantity.
 *
 * Lots (D-17, ADR-023): the pair row is the lock root. lockLots() is called only after every pair of the operation is
 * locked; it locks all lots of the pair by point lookups on (pair, lot_seq) — never a range scan, so no gap locks — and
 * Σ lot quantity = pair quantity is kept by StockService.
 */
interface StockRepositoryInterface
{
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function lockByProductWarehouse(int $productId, int $warehouseId): void;
    public function increment(int $productId, int $warehouseId, int $quantity): void;
    public function decrement(int $productId, int $warehouseId, int $quantity): void;
    public function quantity(int $productId, int $warehouseId): int;
    /** Reserved quantity (locking read inside a transaction, like quantity()). */
    public function reservedQuantity(int $productId, int $warehouseId): int;
    /** reserved += quantity. Not a physical movement: no ledger row. */
    public function reserve(int $productId, int $warehouseId, int $quantity): void;
    /** reserved −= quantity. Not a physical movement: no ledger row. */
    public function release(int $productId, int $warehouseId, int $quantity): void;
    /** Issue of reserved goods: quantity −= n and reserved −= n in one statement, so reserved ≤ quantity holds per row. */
    public function issueReserved(int $productId, int $warehouseId, int $quantity): void;
    /** Units of expired lots of the pair (locking read inside a transaction). */
    public function expiredQuantity(int $productId, int $warehouseId): int;
    /** @return list<StockLot> every lot of the locked pair (empty ones too), locked, in FEFO order */
    public function lockLots(int $productId, int $warehouseId): array;
    /** Creates a lot at lot_seq = lot_count + 1 of the locked pair, quantity 0. */
    public function createLot(int $productId, int $warehouseId, string $code, ?string $expiryDate, string $unitCost, string $sourceType, ?int $sourceId): StockLot;
    /** lot.quantity += delta (signed). The pair must be locked; the unsigned column refuses a negative result. */
    public function changeLotQuantity(int $lotId, int $delta): void;
    /** Read-only lookup outside the stock transaction (pair/product of a return source). */
    public function findLot(int $lotId): ?StockLot;
    /** Business date of the expiry rule (APP_TIMEZONE), from the same clock as the database rows. */
    public function today(): string;
    public function tracksExpiry(int $productId): bool;
    /** Current purchase price per base unit (products.purchase_price), decimal string. */
    public function purchaseCost(int $productId): string;
}
