<?php

declare(strict_types=1);

namespace App\Entity;

final class Customer
{
    /**
     * Price list and tax identity are optional (D-19, ADR-025): the assigned price list prices new SO and quotation lines;
     * NPWP (15/16 digits), NIK (16 digits), tax name and tax address are snapshotted on invoices.
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $email,
        private readonly string $phone,
        private readonly string $address,
        private readonly bool $isActive,
        private readonly ?int $priceListId = null,
        private readonly ?string $npwp = null,
        private readonly ?string $nik = null,
        private readonly ?string $taxName = null,
        private readonly ?string $taxAddress = null,
    ) {
    }

    public function id(): int { return $this->id; }
    public function name(): string { return $this->name; }
    public function email(): string { return $this->email; }
    public function phone(): string { return $this->phone; }
    public function address(): string { return $this->address; }
    public function isActive(): bool { return $this->isActive; }
    public function priceListId(): ?int { return $this->priceListId; }
    public function npwp(): ?string { return $this->npwp; }
    public function nik(): ?string { return $this->nik; }
    public function taxName(): ?string { return $this->taxName; }
    public function taxAddress(): ?string { return $this->taxAddress; }
}
