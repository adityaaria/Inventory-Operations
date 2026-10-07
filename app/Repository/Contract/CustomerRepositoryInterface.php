<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Customer;
use App\Support\PaginatedResult;
use App\Support\Pagination;

interface CustomerRepositoryInterface
{
    /** @return list<Customer> */
    public function all(): array;

    /** @return PaginatedResult<Customer> */
    public function paginate(Pagination $pagination): PaginatedResult;
    public function findById(int $id): ?Customer;
    public function create(string $name, string $email, string $phone, string $address, bool $isActive): Customer;
    public function update(int $id, string $name, string $email, string $phone, string $address): Customer;
    public function setActive(int $id, bool $isActive): void;
}
