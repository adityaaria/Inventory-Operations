<?php

declare(strict_types=1);

namespace App\Entity;

final class Warehouse
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $location,
        private readonly bool $isActive,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
