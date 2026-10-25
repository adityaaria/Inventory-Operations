<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\ProductUnit;
use App\Exception\ValidationException;
use App\Repository\Contract\ProductUnitRepositoryInterface;

/** In-memory product units (D-16, ADR-022) with the same uniqueness rules as the MySQL keys. */
final class InMemoryProductUnitRepository implements ProductUnitRepositoryInterface
{
    /** @var array<int, ProductUnit> */
    private array $units = [];
    private int $nextId = 1;
    /** @var array<string, true> product id + code pairs used on documents */
    private array $used = [];

    /** @param list<ProductUnit> $units */
    public function __construct(array $units = [])
    {
        foreach ($units as $unit) {
            $id = $unit->id() > 0 ? $unit->id() : $this->nextId;
            $this->units[$id] = $this->withId($unit, $id);
            $this->nextId = max($this->nextId, $id + 1);
        }
    }

    /** Test helper: marks a unit code as used on a document line. */
    public function markUsed(int $productId, string $code): void
    {
        $this->used[$productId . '|' . strtolower($code)] = true;
    }

    public function forProduct(int $productId): array
    {
        return array_values(array_filter($this->units, static fn (ProductUnit $unit): bool => $unit->productId() === $productId));
    }

    public function forProducts(array $productIds): array
    {
        $result = [];
        foreach ($productIds as $productId) {
            $units = $this->forProduct((int) $productId);
            if ($units !== []) {
                $result[(int) $productId] = $units;
            }
        }

        return $result;
    }

    public function find(int $unitId): ?ProductUnit
    {
        return $this->units[$unitId] ?? null;
    }

    public function findByBarcode(string $barcode): ?ProductUnit
    {
        foreach ($this->units as $unit) {
            if ($unit->barcode() === $barcode) {
                return $unit;
            }
        }

        return null;
    }

    public function lockForProduct(int $productId): array
    {
        return $this->forProduct($productId);
    }

    public function shareLock(int $productId, array $codes): array
    {
        $lower = array_map('strtolower', $codes);

        return array_values(array_filter($this->forProduct($productId), static fn (ProductUnit $unit): bool => in_array(strtolower($unit->code()), $lower, true)));
    }

    public function create(int $productId, array $unit): ProductUnit
    {
        $entity = new ProductUnit($this->nextId, $productId, $unit['code'], $unit['factor'], $unit['barcode'], $unit['purchase_price'], $unit['selling_price'], $unit['is_base'], $unit['is_purchase_default'], $unit['is_sales_default'], $unit['is_active']);
        $this->assertUnique($entity);
        $this->units[$this->nextId++] = $entity;

        return $entity;
    }

    public function update(int $unitId, array $unit): ProductUnit
    {
        $current = $this->units[$unitId] ?? throw new ValidationException('Unit not found.');
        $entity = new ProductUnit($unitId, $current->productId(), $unit['code'], $unit['factor'], $unit['barcode'], $unit['purchase_price'], $unit['selling_price'], $current->isBase(), $unit['is_purchase_default'], $unit['is_sales_default'], $unit['is_active']);
        $this->assertUnique($entity);
        $this->units[$unitId] = $entity;

        return $entity;
    }

    public function clearDefault(int $productId, string $purpose, int $exceptUnitId): void
    {
        foreach ($this->forProduct($productId) as $unit) {
            if ($unit->id() === $exceptUnitId) {
                continue;
            }
            $this->units[$unit->id()] = new ProductUnit(
                $unit->id(), $unit->productId(), $unit->code(), $unit->factor(), $unit->barcode(), $unit->purchasePrice(), $unit->sellingPrice(), $unit->isBase(),
                $purpose === 'purchase' ? false : $unit->isPurchaseDefault(),
                $purpose === 'sales' ? false : $unit->isSalesDefault(),
                $unit->isActive(),
            );
        }
    }

    public function isUsed(int $productId, string $code): bool
    {
        return isset($this->used[$productId . '|' . strtolower($code)]);
    }

    private function assertUnique(ProductUnit $candidate): void
    {
        foreach ($this->units as $unit) {
            if ($unit->id() === $candidate->id()) {
                continue;
            }
            $sameProduct = $unit->productId() === $candidate->productId();
            if (($sameProduct && strcasecmp($unit->code(), $candidate->code()) === 0)
                || ($candidate->barcode() !== null && $unit->barcode() === $candidate->barcode())
                || ($sameProduct && $unit->isBase() && $candidate->isBase())
                || ($sameProduct && $unit->isPurchaseDefault() && $candidate->isPurchaseDefault())
                || ($sameProduct && $unit->isSalesDefault() && $candidate->isSalesDefault())) {
                throw new ValidationException('A record with that unique value already exists.');
            }
        }
    }

    private function withId(ProductUnit $unit, int $id): ProductUnit
    {
        return new ProductUnit($id, $unit->productId(), $unit->code(), $unit->factor(), $unit->barcode(), $unit->purchasePrice(), $unit->sellingPrice(), $unit->isBase(), $unit->isPurchaseDefault(), $unit->isSalesDefault(), $unit->isActive());
    }
}
