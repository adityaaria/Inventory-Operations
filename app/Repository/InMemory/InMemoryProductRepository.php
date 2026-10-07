<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\ProductInput;
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

    public function active(): array
    {
        $items = array_values(array_filter($this->products, static fn (Product $product): bool => $product->isActive()));
        usort($items, static fn (Product $a, Product $b): int => strcasecmp($a->name(), $b->name()) ?: $a->id() <=> $b->id());
        return $items;
    }

    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function create(ProductInput $input, bool $isActive): Product
    {
        $this->assertSkuAvailable($input->sku, null);
        $product = new Product($this->nextId++, $input->sku, $input->name, $input->unit, $input->purchasePrice, $input->sellingPrice, $input->reorderPoint, $input->categoryId, $isActive);
        $this->products[$product->id()] = $product;

        return $product;
    }

    public function update(int $id, ProductInput $input): Product
    {
        $existing = $this->findRequired($id);
        $this->assertSkuAvailable($input->sku, $id);
        $updated = new Product($id, $input->sku, $input->name, $input->unit, $input->purchasePrice, $input->sellingPrice, $input->reorderPoint, $input->categoryId, $existing->isActive());
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

    public function stocksForProducts(array $productIds): array
    {
        $result = [];
        foreach ($productIds as $id) { $result[$id] = $this->stocksForProduct($id); }
        return $result;
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
