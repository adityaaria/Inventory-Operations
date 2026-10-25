<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Read models of lots (D-17, ADR-023): product lots, the Expiry report, over-reserved pairs and the recall/trace page.
 * Filters: warehouse_id (0 = all), status (flagged = near + expired, near, expired, all = every lot with stock and an
 * expiry date), q (SKU, product name or lot code).
 */
interface LotQueryRepositoryInterface
{
    /** Business date of the expiry rule (APP_TIMEZONE). */
    public function today(): string;
    /** @return list<array<string, mixed>> lots with stock of a product, every warehouse, FEFO order */
    public function productLots(int $productId): array;
    /** @param array{warehouse_id: int, status: string, q: string} $filters @return array{near_lots: int, expired_lots: int, near_units: int, expired_units: int} */
    public function expirySummary(array $filters, int $nearDays): array;
    /** @param array{warehouse_id: int, status: string, q: string} $filters */
    public function expiryCount(array $filters, int $nearDays): int;
    /** @param array{warehouse_id: int, status: string, q: string} $filters @return list<array<string, mixed>> */
    public function expiryPage(array $filters, int $nearDays, int $limit, int $offset): array;
    /** @param array{warehouse_id: int, status: string, q: string} $filters @return iterable<array<string, mixed>> */
    public function iterateExpiry(array $filters, int $nearDays): iterable;
    /** Pairs whose reservation exceeds the non-expired stock, with the open orders holding it. @return list<array<string, mixed>> */
    public function overReserved(): array;
    /** @return list<array<string, mixed>> */
    public function searchLots(string $query, int $limit, int $offset): array;
    public function searchCount(string $query): int;
    /** @return array<string, mixed>|null */
    public function lot(int $id): ?array;
    /** Ledger rows of the lot with their documents (PO + supplier, SO + customer, operation kind). @return list<array<string, mixed>> */
    public function lotMovements(int $id): array;
    /** Transfer lot rows where the lot was the source or the destination. @return list<array<string, mixed>> */
    public function lotTransfers(int $id): array;
    /** Lots with the same code in any product or warehouse (downstream after transfers). @return list<array<string, mixed>> */
    public function sameCodeLots(string $code, int $exceptId): array;
}
