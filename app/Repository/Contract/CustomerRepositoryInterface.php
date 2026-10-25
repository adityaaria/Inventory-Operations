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

    /** Optional tax identity snapshotted on invoices (D-19, ADR-025); values are already normalized (digits only). */
    public function updateTaxProfile(int $id, ?string $npwp, ?string $nik, ?string $taxName, ?string $taxAddress): Customer;

    /** Assigns (or with null removes) the customer's price list (D-19, ADR-025). */
    public function assignPriceList(int $customerId, ?int $priceListId): void;

    /** @return list<Customer> customers assigned to the price list, by name */
    public function forPriceList(int $priceListId): array;
}
