<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Exception\HttpException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Security\AuthContext;
use App\Support\PaginatedResult;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MasterDataAuthorizationService $authorization,
    ) {
    }

    /** @return PaginatedResult<Product> */
    public function search(AuthContext $actor, ProductSearchCriteria $criteria): PaginatedResult
    {
        $this->assertCanRead($actor);

        return $this->products->search($criteria);
    }

    public function create(
        AuthContext $actor,
        string $sku,
        string $name,
        string $unit,
        float $purchasePrice,
        float $sellingPrice,
        int $reorderPoint,
        int $categoryId,
    ): Product {
        $this->assertCanWrite($actor);
        $this->assertValid($sku, $name, $unit, $purchasePrice, $sellingPrice, $reorderPoint, $categoryId);

        return $this->products->create(trim($sku), trim($name), trim($unit), $purchasePrice, $sellingPrice, $reorderPoint, $categoryId, true);
    }

    public function update(
        AuthContext $actor,
        int $id,
        string $sku,
        string $name,
        string $unit,
        float $purchasePrice,
        float $sellingPrice,
        int $reorderPoint,
        int $categoryId,
    ): Product {
        $this->assertCanWrite($actor);
        $this->assertValid($sku, $name, $unit, $purchasePrice, $sellingPrice, $reorderPoint, $categoryId);

        return $this->products->update($id, trim($sku), trim($name), trim($unit), $purchasePrice, $sellingPrice, $reorderPoint, $categoryId);
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanWrite($actor);
        $this->products->setActive($id, $isActive);
    }

    private function assertCanRead(AuthContext $actor): void
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanWrite(AuthContext $actor): void
    {
        if (!$this->authorization->canWrite($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertValid(string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId): void
    {
        if (trim($sku) === '') {
            throw new InvalidArgumentException('SKU is required.');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (trim($unit) === '') {
            throw new InvalidArgumentException('Unit is required.');
        }
        if ($purchasePrice < 0) {
            throw new InvalidArgumentException('Purchase price cannot be negative.');
        }
        if ($sellingPrice < 0) {
            throw new InvalidArgumentException('Selling price cannot be negative.');
        }
        if ($reorderPoint < 0) {
            throw new InvalidArgumentException('Reorder point cannot be negative.');
        }
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Category is required.');
        }
    }
}
