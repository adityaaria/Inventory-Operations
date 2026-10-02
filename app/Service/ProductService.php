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

        return $this->products->create($input->trimmed(), true);
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
