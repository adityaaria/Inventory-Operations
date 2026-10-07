<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthContext;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use InvalidArgumentException;

final class WarehouseService
{
    public function __construct(
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly MasterDataAuthorizationService $authorization,
        private readonly ?StockService $stock = null,
    ) {}

    /** @return list<Warehouse> */
    public function all(AuthContext $actor): array
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
        return $this->warehouses->all();
    }

    /** @return PaginatedResult<Warehouse> */
    public function paginate(AuthContext $actor, Pagination $pagination): PaginatedResult
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
        return $this->warehouses->paginate($pagination);
    }

    public function create(AuthContext $actor, string $name, string $location): Warehouse
    {
        $this->assertCanWrite($actor);
        $this->assertName($name);
        \App\Validation\InputValidator::optionalString('location', $location, 190);
        if ($this->stock === null) { return $this->warehouses->create(trim($name), trim($location), true); }
        return $this->stock->catalogTransaction(function () use ($name, $location): Warehouse {
            $warehouse = $this->warehouses->create(trim($name), trim($location), true);
            $this->stock->initializeWarehouse($warehouse->id());
            return $warehouse;
        });
    }

    public function update(AuthContext $actor, int $id, string $name, string $location): Warehouse
    {
        $this->assertCanWrite($actor);
        $this->assertName($name);
        \App\Validation\InputValidator::optionalString('location', $location, 190);
        return $this->warehouses->update($id, trim($name), trim($location));
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanWrite($actor);
        $this->warehouses->setActive($id, $isActive);
    }

    private function assertCanWrite(AuthContext $actor): void
    {
        if (!$this->authorization->canWrite($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertName(string $name): void
    {
        \App\Validation\InputValidator::requiredString('name', $name, 120);
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
    }
}
