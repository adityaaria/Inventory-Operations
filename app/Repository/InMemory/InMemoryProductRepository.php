<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Exception\EntityNotFoundException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;

final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, Product> */
    private array $products = [];
    /** @var array<string, int> */
    private array $stocks = [];
    /** @var array<string, int> */
    private array $overrides = [];
    /** @var array<string, int> */
    private array $reserved = [];
    /** @var array<int, bool> */
    private array $inactiveWarehouses = [];
    /** @var array<string, int> */
    private array $expired = [];
    /** @var array<string, string|null> */
    private array $nearest = [];
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
        if ($criteria->stockStatus() !== null) {
            $wantLow = $criteria->stockStatus() === 'low';
            $items = array_values(array_filter($items, fn (Product $product): bool => $wantLow === $this->hasLowPair($product->id())));
        }

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
        $product = new Product($this->nextId++, $input->sku, $input->name, $input->unit, $input->purchasePrice, $input->sellingPrice, $input->reorderPoint, $input->categoryId, $isActive, $input->tracksExpiry);
        $this->products[$product->id()] = $product;

        return $product;
    }

    public function update(int $id, ProductInput $input): Product
    {
        $existing = $this->findRequired($id);
        $this->assertSkuAvailable($input->sku, $id);
        $updated = new Product($id, $input->sku, $input->name, $input->unit, $input->purchasePrice, $input->sellingPrice, $input->reorderPoint, $input->categoryId, $existing->isActive(), $input->tracksExpiry);
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
            $existing->tracksExpiry(),
        );
    }

    public function setStockSnapshot(int $productId, int $warehouseId, int $quantity, int $reserved = 0, int $expired = 0, ?string $nearestExpiry = null): void
    {
        $this->stocks[$productId . ':' . $warehouseId] = $quantity;
        $this->reserved[$productId . ':' . $warehouseId] = $reserved;
        $this->expired[$productId . ':' . $warehouseId] = $expired;
        $this->nearest[$productId . ':' . $warehouseId] = $nearestExpiry;
    }

    public function setWarehouseActive(int $warehouseId, bool $isActive): void
    {
        if ($isActive) {
            unset($this->inactiveWarehouses[$warehouseId]);
        } else {
            $this->inactiveWarehouses[$warehouseId] = true;
        }
    }

    public function stocksForProduct(int $productId): array
    {
        $this->findRequired($productId);
        $stocks = [];
        foreach (array_keys($this->stocks) as $key) {
            [$storedProductId, $warehouseId] = array_map('intval', explode(':', $key));
            if ($storedProductId === $productId) {
                $stocks[] = $this->stock($productId, $warehouseId);
            }
        }

        return $stocks;
    }

    public function lockStock(int $productId, int $warehouseId): ?ProductStock
    {
        if (!isset($this->products[$productId], $this->stocks[$productId . ':' . $warehouseId])) {
            return null;
        }

        return $this->stock($productId, $warehouseId);
    }

    public function setReorderPointOverride(int $productId, int $warehouseId, ?int $override): void
    {
        $key = $productId . ':' . $warehouseId;
        if ($override === null) {
            unset($this->overrides[$key]);
        } else {
            $this->overrides[$key] = $override;
        }
    }

    private function stock(int $productId, int $warehouseId): ProductStock
    {
        $product = $this->findRequired($productId);
        $key = $productId . ':' . $warehouseId;

        return new ProductStock(
            $productId,
            $warehouseId,
            $product->sku(),
            $product->name(),
            'Warehouse ' . $warehouseId,
            $this->stocks[$key] ?? 0,
            $product->reorderPoint(),
            $this->overrides[$key] ?? null,
            !isset($this->inactiveWarehouses[$warehouseId]),
            $product->isActive(),
            $this->reserved[$key] ?? 0,
            0,
            $this->expired[$key] ?? 0,
            $this->nearest[$key] ?? null,
        );
    }

    private function hasLowPair(int $productId): bool
    {
        foreach ($this->stocksForProduct($productId) as $stock) {
            if ($stock->isLowStock()) {
                return true;
            }
        }

        return false;
    }

    public function stocksForProducts(array $productIds): array
    {
        $result = [];
        foreach ($productIds as $id) { $result[$id] = $this->stocksForProduct($id); }
        return $result;
    }

    private function findRequired(int $id): Product
    {
        return $this->findById($id) ?? throw new EntityNotFoundException("Product not found: {$id}");
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
