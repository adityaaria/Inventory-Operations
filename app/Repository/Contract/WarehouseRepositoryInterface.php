<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Warehouse;

interface WarehouseRepositoryInterface
{
    /** @return list<Warehouse> */
    public function all(): array;
    /** @return list<Warehouse> */
    public function active(): array;
    public function findById(int $id): ?Warehouse;
    public function create(string $name, string $location, bool $isActive): Warehouse;
    public function update(int $id, string $name, string $location): Warehouse;
    public function setActive(int $id, bool $isActive): void;
}
