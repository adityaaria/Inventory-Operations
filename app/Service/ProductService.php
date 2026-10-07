<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Exception\HttpException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Security\AuthContext;
use App\Support\PaginatedResult;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MasterDataAuthorizationService $authorization,
        private readonly ?StockService $stock = null,
    ) {
    }

    /** @return PaginatedResult<Product> */
    public function search(AuthContext $actor, ProductSearchCriteria $criteria): PaginatedResult
    {
        $this->assertCanRead($actor);

        return $this->products->search($criteria);
    }

    public function create(AuthContext $actor, ProductInput $input): Product
    {
        $this->assertCanWrite($actor);
        $this->assertValid($input);

        if ($this->stock === null) { return $this->products->create($input->trimmed(), true); }
        return $this->stock->catalogTransaction(function () use ($input): Product {
            $product = $this->products->create($input->trimmed(), true);
            $this->stock->initializeProduct($product->id());
            return $product;
        });
    }

    public function update(AuthContext $actor, int $id, ProductInput $input): Product
    {
        $this->assertCanWrite($actor);
        $this->assertValid($input);

        return $this->products->update($id, $input->trimmed());
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanWrite($actor);
        $this->products->setActive($id, $isActive);
    }

    public function detail(AuthContext $actor, int $id): Product
    {
        $this->assertCanRead($actor);
        return $this->products->findById($id) ?? throw new HttpException(404, 'Product not found.');
    }

    private function assertCanRead(AuthContext $actor): void
    {
        // Unreachable via the public API: AuthContext's constructor already rejects any role
        // outside User::ROLES, so canRead() (in_array($role, User::ROLES)) can never be false here.
        // Kept as a defensive guard in case that invariant ever changes.
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

    private function assertValid(ProductInput $input): void
    {
        \App\Validation\InputValidator::requiredString('sku', $input->sku, 80);
        \App\Validation\InputValidator::requiredString('name', $input->name, 160);
        \App\Validation\InputValidator::requiredString('unit', $input->unit, 30);
        \App\Validation\InputValidator::nonNegativeMoney('purchase_price', $input->purchasePrice);
        \App\Validation\InputValidator::nonNegativeMoney('selling_price', $input->sellingPrice);
        if (trim($input->sku) === '') {
            throw new InvalidArgumentException('SKU is required.');
        }
        if (trim($input->name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (trim($input->unit) === '') {
            throw new InvalidArgumentException('Unit is required.');
        }
        if ($input->purchasePrice < 0) {
            throw new InvalidArgumentException('Purchase price cannot be negative.');
        }
        if ($input->sellingPrice < 0) {
            throw new InvalidArgumentException('Selling price cannot be negative.');
        }
        if ($input->reorderPoint < 0) {
            throw new InvalidArgumentException('Reorder point cannot be negative.');
        }
        if ($input->categoryId <= 0) {
            throw new InvalidArgumentException('Category is required.');
        }
    }
}
