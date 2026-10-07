<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Supplier;
use App\Support\PaginatedResult;
use App\Support\Pagination;

interface SupplierRepositoryInterface
{
    /** @return list<Supplier> */
    public function all(): array;

    /** @return PaginatedResult<Supplier> */
    public function paginate(Pagination $pagination): PaginatedResult;
    public function findById(int $id): ?Supplier;
    public function create(string $name, string $email, string $phone, string $address, bool $isActive): Supplier;
    public function update(int $id, string $name, string $email, string $phone, string $address): Supplier;
    public function setActive(int $id, bool $isActive): void;
}
