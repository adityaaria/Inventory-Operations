<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Category;
use App\Support\PaginatedResult;
use App\Support\Pagination;

interface CategoryRepositoryInterface
{
    /** @return list<Category> */
    public function all(): array;

    /** @return PaginatedResult<Category> */
    public function paginate(Pagination $pagination): PaginatedResult;
    /** @return list<Category> */
    public function active(): array;
    public function findById(int $id): ?Category;
    public function create(string $name, string $description, bool $isActive): Category;
    public function update(int $id, string $name, string $description): Category;
    public function setActive(int $id, bool $isActive): void;
}
