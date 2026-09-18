<?php

declare(strict_types=1);

namespace App\Entity;

final class Supplier
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $email,
        private readonly string $phone,
        private readonly string $address,
        private readonly bool $isActive,
    ) {
    }

    public function id(): int { return $this->id; }
    public function name(): string { return $this->name; }
    public function email(): string { return $this->email; }
    public function phone(): string { return $this->phone; }
    public function address(): string { return $this->address; }
    public function isActive(): bool { return $this->isActive; }
}
