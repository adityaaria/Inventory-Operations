<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\InfrastructureException;
use App\Exception\ValidationException;
use App\Repository\Contract\LotQueryRepositoryInterface;
use App\Security\AuthContext;
use App\Support\LotStatus;
use App\Support\PaginatedResult;
use App\Support\Pagination;

/**
 * Lot read models (D-17, ADR-023): Expiry report and CSV, recall/trace and product lots. Expiry report and trace are
 * Admin/WarehouseStaff (403 otherwise, checked before any query); product lots are visible to every role that sees the
 * product page, the unit cost only to Admin/WarehouseStaff.
 */
final class LotService
{
    public const STATUSES = ['flagged', 'near', 'expired', 'all'];
    public const CSV_COLUMNS = ['SKU', 'Product', 'Warehouse', 'Lot', 'ExpiryDate', 'DaysLeft', 'Status', 'Quantity', 'BaseUnit', 'UnitCost'];

    public function __construct(private readonly LotQueryRepositoryInterface $lots, private readonly int $nearDays = 30)
    {
        if ($nearDays < 1 || $nearDays > 365) {
            throw new \InvalidArgumentException('NEAR_EXPIRY_DAYS must be between 1 and 365.');
        }
    }

    public function nearDays(): int
    {
        return $this->nearDays;
    }

    public function authorize(AuthContext $actor): void
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    /** @param array<string, mixed> $input @return array{warehouse_id: int, status: string, q: string} */
    public function filters(array $input): array
    {
        $warehouse = $input['warehouse_id'] ?? '';
        $status = $input['status'] ?? 'flagged';
        $query = $input['q'] ?? '';
        if (!is_string($warehouse) || ($warehouse !== '' && preg_match('/^[1-9]\d{0,9}$/D', $warehouse) !== 1)) {
            throw new ValidationException('Invalid warehouse.');
        }
        if (!is_string($status) || !in_array($status === '' ? 'flagged' : $status, self::STATUSES, true)) {
            throw new ValidationException('Invalid expiry status.');
        }
        if (!is_string($query) || strlen($query) > 120) {
            throw new ValidationException('Invalid search.');
        }

        return ['warehouse_id' => (int) $warehouse, 'status' => $status === '' ? 'flagged' : $status, 'q' => trim($query)];
    }

    /** @param array<string, mixed> $input @return array{filters: array{warehouse_id: int, status: string, q: string}, summary: array<string, int>, result: PaginatedResult<array<string, mixed>>, over_reserved: list<array<string, mixed>>, today: string, near_days: int} */
    public function expiryReport(AuthContext $actor, array $input): array
    {
        $this->authorize($actor);
        $filters = $this->filters($input);
        $pagination = Pagination::fromArray($input);
        $total = $this->lots->expiryCount($filters, $this->nearDays);
        $rows = array_map(fn (array $row): array => $this->decorate($row), $this->lots->expiryPage($filters, $this->nearDays, Pagination::PER_PAGE, $pagination->offsetForTotal($total)));

        return [
            'filters' => $filters,
            'summary' => $this->lots->expirySummary($filters, $this->nearDays),
            'result' => new PaginatedResult($rows, $total, $pagination->pageForTotal($total), Pagination::PER_PAGE),
            'over_reserved' => $this->lots->overReserved(),
            'today' => $this->lots->today(),
            'near_days' => $this->nearDays,
        ];
    }

    /** Streams the full filtered report as CSV (formula-injection safe, like the other reports). @param array<string, mixed> $input */
    public function expiryCsv(AuthContext $actor, array $input): \Closure
    {
        $this->authorize($actor);
        $filters = $this->filters($input);

        return function () use ($filters): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new InfrastructureException('Unable to open CSV output.');
            }
            try {
                fputcsv($handle, self::CSV_COLUMNS, ',', '"', '\\');
                foreach ($this->lots->iterateExpiry($filters, $this->nearDays) as $row) {
                    $row = $this->decorate($row);
                    fputcsv($handle, array_map(static fn (mixed $value): string => self::csvValue($value), [
                        $row['sku'], $row['product_name'], $row['warehouse_name'], $row['lot_code'], $row['expiry_date'], $row['days_left'], $row['status'], $row['quantity'], $row['base_unit'] ?? '', $row['unit_cost'],
                    ]), ',', '"', '\\');
                }
            } finally {
                fclose($handle);
            }
        };
    }

    /** @param array<string, mixed> $input @return PaginatedResult<array<string, mixed>> */
    public function search(AuthContext $actor, array $input): PaginatedResult
    {
        $this->authorize($actor);
        $query = $input['q'] ?? '';
        if (!is_string($query) || strlen($query) > 120) {
            throw new ValidationException('Invalid search.');
        }
        $query = trim($query);
        $pagination = Pagination::fromArray($input);
        $total = $query === '' ? 0 : $this->lots->searchCount($query);
        $rows = $query === '' ? [] : array_map(fn (array $row): array => $this->decorate($row), $this->lots->searchLots($query, Pagination::PER_PAGE, $pagination->offsetForTotal($total)));

        return new PaginatedResult($rows, $total, $pagination->pageForTotal($total), Pagination::PER_PAGE);
    }

    /** Recall/trace of one lot. @return array{lot: array<string, mixed>, movements: list<array<string, mixed>>, transfers: list<array<string, mixed>>, same_code: list<array<string, mixed>>} */
    public function trace(AuthContext $actor, int $id): array
    {
        $this->authorize($actor);
        $lot = $this->lots->lot($id) ?? throw new HttpException(404, 'Lot not found.');

        return [
            'lot' => $this->decorate($lot),
            'movements' => $this->lots->lotMovements($id),
            'transfers' => $this->lots->lotTransfers($id),
            'same_code' => $this->lots->sameCodeLots((string) $lot['lot_code'], $id),
        ];
    }

    /** Lots with stock of a product for its detail page; cost only for Admin/WarehouseStaff. @return list<array<string, mixed>> */
    public function productLots(AuthContext $actor, int $productId): array
    {
        $canSeeCost = in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true);

        return array_map(function (array $row) use ($canSeeCost): array {
            $row = $this->decorate($row);
            if (!$canSeeCost) {
                unset($row['unit_cost']);
            }

            return $row;
        }, $this->lots->productLots($productId));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decorate(array $row): array
    {
        $expiry = isset($row['expiry_date']) ? (string) $row['expiry_date'] : null;
        $days = $expiry === null ? null : (isset($row['days_left']) ? (int) $row['days_left'] : null);
        $row['days_left'] = $days;
        $row['status'] = $expiry === null || $days === null ? LotStatus::NONE : ($days < 0 ? LotStatus::EXPIRED : ($days <= $this->nearDays ? LotStatus::NEAR : LotStatus::OK));

        return $row;
    }

    private static function csvValue(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }
}
