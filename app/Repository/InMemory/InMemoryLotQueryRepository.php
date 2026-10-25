<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\LotQueryRepositoryInterface;

/** Test double of the lot read models (D-17): rows are supplied as they would come from MySQL (days_left included). */
final class InMemoryLotQueryRepository implements LotQueryRepositoryInterface
{
    /** @param list<array<string, mixed>> $lots @param list<array<string, mixed>> $overReserved @param list<array<string, mixed>> $movements */
    public function __construct(private readonly array $lots = [], private readonly array $overReserved = [], private readonly array $movements = [], private readonly string $today = '2026-10-12')
    {
    }

    public function today(): string { return $this->today; }

    public function productLots(int $productId): array
    {
        return array_values(array_filter($this->lots, static fn (array $lot): bool => (int) $lot['product_id'] === $productId && (int) $lot['quantity'] > 0));
    }

    public function expirySummary(array $filters, int $nearDays): array
    {
        $rows = $this->matching(['status' => 'flagged'] + $filters, $nearDays);
        $expired = array_filter($rows, static fn (array $row): bool => (int) $row['days_left'] < 0);
        $near = array_filter($rows, static fn (array $row): bool => (int) $row['days_left'] >= 0);

        return ['near_lots' => count($near), 'expired_lots' => count($expired), 'near_units' => array_sum(array_column($near, 'quantity')), 'expired_units' => array_sum(array_column($expired, 'quantity'))];
    }

    public function expiryCount(array $filters, int $nearDays): int { return count($this->matching($filters, $nearDays)); }
    public function expiryPage(array $filters, int $nearDays, int $limit, int $offset): array { return array_slice($this->matching($filters, $nearDays), $offset, $limit); }
    public function iterateExpiry(array $filters, int $nearDays): iterable { yield from $this->matching($filters, $nearDays); }
    public function overReserved(): array { return $this->overReserved; }

    public function searchLots(string $query, int $limit, int $offset): array
    {
        return array_slice($this->search($query), $offset, $limit);
    }

    public function searchCount(string $query): int { return count($this->search($query)); }

    public function lot(int $id): ?array
    {
        foreach ($this->lots as $lot) {
            if ((int) $lot['id'] === $id) {
                return $lot;
            }
        }

        return null;
    }

    public function lotMovements(int $id): array { return array_values(array_filter($this->movements, static fn (array $row): bool => (int) ($row['lot_id'] ?? 0) === $id)); }
    public function lotTransfers(int $id): array { return []; }

    public function sameCodeLots(string $code, int $exceptId): array
    {
        return array_values(array_filter($this->lots, static fn (array $lot): bool => $lot['lot_code'] === $code && (int) $lot['id'] !== $exceptId));
    }

    /** @return list<array<string, mixed>> */
    private function search(string $query): array
    {
        return array_values(array_filter($this->lots, static fn (array $lot): bool => stripos((string) $lot['lot_code'], $query) !== false || stripos((string) ($lot['sku'] ?? ''), $query) !== false));
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function matching(array $filters, int $nearDays): array
    {
        return array_values(array_filter($this->lots, static function (array $lot) use ($filters, $nearDays): bool {
            if ((int) $lot['quantity'] <= 0 || ($lot['expiry_date'] ?? null) === null) {
                return false;
            }
            $days = (int) $lot['days_left'];
            $status = $filters['status'] ?? 'flagged';
            if (($status === 'expired' && $days >= 0) || ($status === 'near' && ($days < 0 || $days > $nearDays)) || ($status === 'flagged' && $days > $nearDays)) {
                return false;
            }
            if (($filters['warehouse_id'] ?? 0) > 0 && (int) $lot['warehouse_id'] !== (int) $filters['warehouse_id']) {
                return false;
            }

            return ($filters['q'] ?? '') === '' || stripos((string) $lot['lot_code'] . ' ' . ($lot['sku'] ?? '') . ' ' . ($lot['product_name'] ?? ''), (string) $filters['q']) !== false;
        }));
    }
}
