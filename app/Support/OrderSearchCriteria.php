<?php

declare(strict_types=1);

namespace App\Support;

final class OrderSearchCriteria
{
    private const SORT_COLUMNS = ['order_number', 'order_date', 'status', 'party', 'warehouse'];
    private const DIRECTIONS = ['asc', 'desc'];
    private const STATUSES = ['Draft', 'Ordered', 'PartiallyReceived', 'Received', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled'];

    private function __construct(
        private readonly string $term,
        private readonly ?string $status,
        private readonly int $page,
        private readonly int $perPage,
        private readonly string $sortBy,
        private readonly string $direction,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        foreach ($input as $key => $value) {
            if (!is_string($value) && !is_int($value)) { unset($input[$key]); }
        }
        $status = isset($input['status']) && in_array($input['status'], self::STATUSES, true) ? (string) $input['status'] : null;
        $sortBy = isset($input['sort']) && in_array($input['sort'], self::SORT_COLUMNS, true) ? (string) $input['sort'] : 'order_date';
        $direction = isset($input['direction']) && in_array(strtolower((string) $input['direction']), self::DIRECTIONS, true)
            ? strtolower((string) $input['direction'])
            : 'desc';

        return new self(trim((string) ($input['q'] ?? '')), $status, max(1, (int) ($input['page'] ?? 1)), min(10, max(1, (int) ($input['per_page'] ?? 10))), $sortBy, $direction);
    }

    public function term(): string { return $this->term; }
    public function status(): ?string { return $this->status; }
    public function page(): int { return $this->page; }
    public function perPage(): int { return $this->perPage; }
    public function sortBy(): string { return $this->sortBy; }
    public function direction(): string { return $this->direction; }
    public function offset(): int { return ($this->page - 1) * $this->perPage; }
}
