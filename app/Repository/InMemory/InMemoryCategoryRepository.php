<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Category;
use App\Exception\EntityNotFoundException;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\Pagination;

final class InMemoryCategoryRepository implements CategoryRepositoryInterface
{
    /** @var array<int, Category> */
    private array $categories = [];
    private int $nextId = 1;

    /** @param list<Category> $categories */
    public function __construct(array $categories = [])
    {
        foreach ($categories as $category) {
            $this->categories[$category->id()] = $category;
            $this->nextId = max($this->nextId, $category->id() + 1);
        }
    }

    public function paginate(Pagination $pagination): PaginatedResult
    {
        $items = $this->all();
        usort($items, static fn (Category $a, Category $b): int => strcasecmp($a->name(), $b->name()) ?: $a->id() <=> $b->id());
        $total = count($items);

        return new PaginatedResult(
            array_slice($items, $pagination->offsetForTotal($total), Pagination::PER_PAGE),
            $total,
            $pagination->pageForTotal($total),
            Pagination::PER_PAGE,
        );
    }

    public function all(): array
    {
        return array_values($this->categories);
    }

    public function active(): array
    {
        return array_values(array_filter($this->categories, static fn (Category $category): bool => $category->isActive()));
    }

    public function findById(int $id): ?Category
    {
        return $this->categories[$id] ?? null;
    }

    public function create(string $name, string $description, bool $isActive): Category
    {
        $category = new Category($this->nextId++, $name, $description, $isActive);
        $this->categories[$category->id()] = $category;

        return $category;
    }

    public function update(int $id, string $name, string $description): Category
    {
        $category = $this->findRequired($id);
        $updated = new Category($id, $name, $description, $category->isActive());
        $this->categories[$id] = $updated;

        return $updated;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $category = $this->findRequired($id);
        $this->categories[$id] = new Category($id, $category->name(), $category->description(), $isActive);
    }

    private function findRequired(int $id): Category
    {
        return $this->findById($id) ?? throw new EntityNotFoundException("Category not found: {$id}");
    }
}
