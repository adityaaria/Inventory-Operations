<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\ProductUnitRepositoryInterface;
use App\Support\UnitSet;

/**
 * Read model for product units (D-16, ADR-022): services convert input with it and views format quantities with it.
 * Without a unit repository (older unit-test wiring) every product has only its base unit (products.unit, factor 1).
 */
final class ProductUnitCatalog
{
    /** @var array<int, UnitSet> */
    private array $cache = [];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ?ProductUnitRepositoryInterface $units = null,
    ) {
    }

    public function repository(): ?ProductUnitRepositoryInterface
    {
        return $this->units;
    }

    public function forProduct(Product $product): UnitSet
    {
        return $this->cache[$product->id()] ??= UnitSet::forProduct($product, $this->units?->forProduct($product->id()) ?? []);
    }

    /** @param list<int> $productIds @return array<int, UnitSet> */
    public function forProductIds(array $productIds): array
    {
        $missing = array_values(array_diff(array_unique(array_map('intval', $productIds)), array_keys($this->cache)));
        $stored = $missing === [] ? [] : ($this->units?->forProducts($missing) ?? []);
        foreach ($missing as $productId) {
            $product = $this->products->findById($productId);
            $this->cache[$productId] = $product === null ? UnitSet::baseOnly('unit') : UnitSet::forProduct($product, $stored[$productId] ?? []);
        }
        $result = [];
        foreach ($productIds as $productId) {
            $result[(int) $productId] = $this->cache[(int) $productId];
        }

        return $result;
    }

    /** @param list<Product> $products @return array<int, UnitSet> */
    public function forProducts(array $products): array
    {
        $ids = array_map(static fn (Product $product): int => $product->id(), $products);
        $stored = $this->units?->forProducts(array_values(array_diff($ids, array_keys($this->cache)))) ?? [];
        $result = [];
        foreach ($products as $product) {
            $result[$product->id()] = $this->cache[$product->id()] ??= UnitSet::forProduct($product, $stored[$product->id()] ?? []);
        }

        return $result;
    }

    /**
     * Inside the document transaction: takes a shared lock on every non-base unit a line snapshots and confirms its factor
     * is unchanged and the unit still active. A concurrent factor change (ProductUnitService locks the rows FOR UPDATE and
     * then checks usage) therefore either waits for this transaction and sees the new line as "used", or committed first
     * and this check refuses the stale snapshot. The base unit (factor 1) never changes factor and is never inactive.
     *
     * @param list<array{product_id: int, unit_code: string, unit_factor: int}> $lines
     */
    public function confirmSnapshots(array $lines): void
    {
        if ($this->units === null) {
            return;
        }
        foreach ($lines as $line) {
            if ($line['unit_factor'] === 1) {
                continue;
            }
            $locked = $this->units->shareLock($line['product_id'], [$line['unit_code']]);
            $unit = $locked[0] ?? null;
            if ($unit === null || !$unit->isActive() || $unit->factor() !== $line['unit_factor']) {
                throw new \App\Exception\ValidationException('Unit "' . $line['unit_code'] . '" changed while the document was being saved. Reload the form and try again.');
            }
        }
    }

    /** Forgets cached sets (after a unit change in the same request). */
    public function forget(int $productId): void
    {
        unset($this->cache[$productId]);
    }
}
