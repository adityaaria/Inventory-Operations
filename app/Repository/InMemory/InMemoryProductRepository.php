<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;
use RuntimeException;

final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, Product> */
    private array $products = [];
    /** @var array<string, int> */
    private array $stocks = [];
    private int $nextId = 1;

    /** @param list<Product> $products */
    public function __construct(array $products = [])
    {
        foreach ($products as $product) {
            $this->products[$product->id()] = $product;
            $this->nextId = max($this->nextId, $product->id() + 1);
        }
    }

    public function search(ProductSearchCriteria $criteria): PaginatedResult
    {
        $items = array_values(array_filter($this->products, static function (Product $product) use ($criteria): bool {
            if ($criteria->term() !== '' && stripos($product->name() . ' ' . $product->sku(), $criteria->term()) === false) {
                return false;
            }

            return $criteria->categoryId() === null || $product->categoryId() === $criteria->categoryId();
        }));

        usort($items, static fn (Product $a, Product $b): int => strcasecmp($a->name(), $b->name()));

        return new PaginatedResult(
            array_slice($items, $criteria->offset(), $criteria->perPage()),
            count($items),
            $criteria->page(),
            $criteria->perPage(),
        );
    }

    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function create(string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId, bool $isActive): Product
    {
        $this->assertSkuAvailable($sku, null);
        $product = new Product($this->nextId++, $sku, $name, $unit, $purchasePrice, $sellingPrice, $reorderPoint, $categoryId, $isActive);
        $this->products[$product->id()] = $product;

        return $product;
    }

    public function update(int $id, string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId): Product
    {
        $existing = $this->findRequired($id);
        $this->assertSkuAvailable($sku, $id);
        $updated = new Product($id, $sku, $name, $unit, $purchasePrice, $sellingPrice, $reorderPoint, $categoryId, $existing->isActive());
        $this->products[$id] = $updated;

        return $updated;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $existing = $this->findRequired($id);
        $this->products[$id] = new Product(
            $id,
            $existing->sku(),
            $existing->name(),
            $existing->unit(),
            $existing->purchasePrice(),
            $existing->sellingPrice(),
            $existing->reorderPoint(),
            $existing->categoryId(),
            $isActive,
        );
    }

    public function setStockSnapshot(int $productId, int $warehouseId, int $quantity): void
    {
        $this->stocks[$productId . ':' . $warehouseId] = $quantity;
    }

    public function stocksForProduct(int $productId): array
    {
        $product = $this->findRequired($productId);
        $stocks = [];
        foreach ($this->stocks as $key => $quantity) {
            [$storedProductId, $warehouseId] = array_map('intval', explode(':', $key));
            if ($storedProductId === $productId) {
                $stocks[] = new ProductStock(
                    $productId,
                    $warehouseId,
                    $product->sku(),
                    $product->name(),
                    'Warehouse ' . $warehouseId,
                    $quantity,
                    $product->reorderPoint(),
                );
            }
        }

        return $stocks;
    }

    private function findRequired(int $id): Product
    {
        return $this->findById($id) ?? throw new RuntimeException("Product not found: {$id}");
    }

    private function assertSkuAvailable(string $sku, ?int $exceptId): void
    {
        foreach ($this->products as $product) {
            if ($product->sku() === $sku && $product->id() !== $exceptId) {
                throw new InvalidArgumentException('SKU already exists.');
            }
        }
    }
}
