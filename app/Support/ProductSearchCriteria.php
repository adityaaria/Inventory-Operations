<?php

declare(strict_types=1);

namespace App\Support;

final class ProductSearchCriteria
{
    private const SORT_COLUMNS = ['name', 'sku', 'price', 'quantity'];
    private const DIRECTIONS = ['asc', 'desc'];
    private const STOCK_STATUSES = ['low', 'normal'];

    private function __construct(
        private readonly string $term,
        private readonly ?int $categoryId,
        private readonly ?string $stockStatus,
        private readonly int $page,
        private readonly int $perPage,
        private readonly string $sortBy,
        private readonly string $direction,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        foreach ($input as $key => $value) {
            if (!is_string($value) && !is_int($value)) unset($input[$key]);
        }
        $categoryId = isset($input['category_id']) && (int) $input['category_id'] > 0
            ? (int) $input['category_id']
            : null;
        $stockStatus = isset($input['stock_status']) && in_array($input['stock_status'], self::STOCK_STATUSES, true)
            ? (string) $input['stock_status']
            : null;
        $sortBy = isset($input['sort']) && in_array($input['sort'], self::SORT_COLUMNS, true)
            ? (string) $input['sort']
            : 'name';
        $direction = isset($input['direction']) && in_array(strtolower((string) $input['direction']), self::DIRECTIONS, true)
            ? strtolower((string) $input['direction'])
            : 'asc';

        return new self(
            trim((string) ($input['q'] ?? '')),
            $categoryId,
            $stockStatus,
            max(1, (int) ($input['page'] ?? 1)),
            min(10, max(1, (int) ($input['per_page'] ?? 10))),
            $sortBy,
            $direction,
        );
    }

    public function term(): string { return $this->term; }
    public function categoryId(): ?int { return $this->categoryId; }
    public function stockStatus(): ?string { return $this->stockStatus; }
    public function page(): int { return $this->page; }
    public function perPage(): int { return $this->perPage; }
    public function sortBy(): string { return $this->sortBy; }
    public function direction(): string { return $this->direction; }
    public function offset(): int { return ($this->page - 1) * $this->perPage; }
}
