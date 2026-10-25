<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Customer;
use App\Exception\EntityNotFoundException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\Pagination;

/** Customers for unit tests (D-19, ADR-025: price list assignment and tax profile). */
final class InMemoryCustomerRepository implements CustomerRepositoryInterface
{
    /** @var array<int, Customer> */
    private array $customers = [];
    private int $nextId = 1;

    /** @param list<Customer> $customers */
    public function __construct(array $customers = [])
    {
        foreach ($customers as $customer) {
            $this->customers[$customer->id()] = $customer;
            $this->nextId = max($this->nextId, $customer->id() + 1);
        }
    }

    public function all(): array
    {
        $all = array_values($this->customers);
        usort($all, static fn (Customer $a, Customer $b): int => [$a->name(), $a->id()] <=> [$b->name(), $b->id()]);

        return $all;
    }

    public function paginate(Pagination $pagination): PaginatedResult
    {
        $all = $this->all();

        return new PaginatedResult(array_slice($all, $pagination->offsetForTotal(count($all)), Pagination::PER_PAGE), count($all), $pagination->pageForTotal(count($all)), Pagination::PER_PAGE);
    }

    public function findById(int $id): ?Customer
    {
        return $this->customers[$id] ?? null;
    }

    public function create(string $name, string $email, string $phone, string $address, bool $isActive): Customer
    {
        $customer = new Customer($this->nextId++, $name, $email, $phone, $address, $isActive);
        $this->customers[$customer->id()] = $customer;

        return $customer;
    }

    public function update(int $id, string $name, string $email, string $phone, string $address): Customer
    {
        $old = $this->required($id);

        return $this->customers[$id] = new Customer($id, $name, $email, $phone, $address, $old->isActive(), $old->priceListId(), $old->npwp(), $old->nik(), $old->taxName(), $old->taxAddress());
    }

    public function setActive(int $id, bool $isActive): void
    {
        $old = $this->required($id);
        $this->customers[$id] = new Customer($id, $old->name(), $old->email(), $old->phone(), $old->address(), $isActive, $old->priceListId(), $old->npwp(), $old->nik(), $old->taxName(), $old->taxAddress());
    }

    public function updateTaxProfile(int $id, ?string $npwp, ?string $nik, ?string $taxName, ?string $taxAddress): Customer
    {
        $old = $this->required($id);

        return $this->customers[$id] = new Customer($id, $old->name(), $old->email(), $old->phone(), $old->address(), $old->isActive(), $old->priceListId(), $npwp, $nik, $taxName, $taxAddress);
    }

    public function assignPriceList(int $customerId, ?int $priceListId): void
    {
        $old = $this->required($customerId);
        $this->customers[$customerId] = new Customer($customerId, $old->name(), $old->email(), $old->phone(), $old->address(), $old->isActive(), $priceListId, $old->npwp(), $old->nik(), $old->taxName(), $old->taxAddress());
    }

    public function forPriceList(int $priceListId): array
    {
        return array_values(array_filter($this->all(), static fn (Customer $customer): bool => $customer->priceListId() === $priceListId));
    }

    private function required(int $id): Customer
    {
        return $this->customers[$id] ?? throw new EntityNotFoundException('Customer not found: ' . $id);
    }
}
