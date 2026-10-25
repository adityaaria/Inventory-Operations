<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\ProductUnit;

/** Units of measure per product (D-16, ADR-022). */
interface ProductUnitRepositoryInterface
{
    /** @return list<ProductUnit> every unit of the product, active or not */
    public function forProduct(int $productId): array;

    /** @param list<int> $productIds @return array<int, list<ProductUnit>> keyed by product id */
    public function forProducts(array $productIds): array;

    public function find(int $unitId): ?ProductUnit;

    /** Unit carrying this exact barcode (UNIQUE uq_product_units_barcode), active or not (D-18, ADR-024). */
    public function findByBarcode(string $barcode): ?ProductUnit;

    /** Locks every unit row of the product (FOR UPDATE) inside the caller's transaction. @return list<ProductUnit> */
    public function lockForProduct(int $productId): array;

    /**
     * Shared lock on the named unit rows inside the caller's transaction (order/stock line creation re-checks the factor
     * it snapshots; a concurrent factor change waits or is seen). @param list<string> $codes @return list<ProductUnit>
     */
    public function shareLock(int $productId, array $codes): array;

    /**
     * @param array{code: string, factor: int, barcode: ?string, purchase_price: ?float, selling_price: ?float, is_base: bool,
     *              is_purchase_default: bool, is_sales_default: bool, is_active: bool} $unit
     */
    public function create(int $productId, array $unit): ProductUnit;

    /**
     * @param array{code: string, factor: int, barcode: ?string, purchase_price: ?float, selling_price: ?float,
     *              is_purchase_default: bool, is_sales_default: bool, is_active: bool} $unit
     */
    public function update(int $unitId, array $unit): ProductUnit;

    /** Clears the purchase or sales default flag of every other unit of the product ('purchase' | 'sales'). */
    public function clearDefault(int $productId, string $purpose, int $exceptUnitId): void;

    /** True when any PO/SO line or stock-operation line of the product snapshotted this unit code. */
    public function isUsed(int $productId, string $code): bool;
}
