<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Exception\PersistenceException;
use App\Repository\Contract\QuotationRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Support\BusinessDate;
use App\Support\PaginatedResult;
use App\Support\QuotationStatus;
use App\Support\TaxSettings;

/** Quotations for unit tests (D-19, ADR-025). */
final class InMemoryQuotationRepository implements QuotationRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $quotations = [];
    private int $nextId = 1;
    private int $nextLine = 1;

    public function __construct(private readonly ?SalesOrderRepositoryInterface $orders = null, private ?string $today = null)
    {
    }

    public function setToday(string $today): void
    {
        $this->today = $today;
    }

    public function create(string $number, int $customerId, int $warehouseId, int $createdBy, string $quoteDate, string $validUntil, string $notes, array $lines): int
    {
        $id = $this->nextId++;
        $stored = [];
        foreach ($lines as $line) {
            $stored[] = [
                'id' => $this->nextLine++, 'product_id' => $line['product_id'], 'sku' => '#' . $line['product_id'], 'product_name' => '', 'unit_code' => $line['unit_code'],
                'unit_factor' => $line['unit_factor'], 'unit_quantity' => $line['unit_quantity'], 'quantity' => $line['quantity'],
                'unit_price' => number_format($line['selling_price'], 2, '.', ''), 'list_price' => number_format($line['list_price'], 2, '.', ''),
                'price_reason' => $line['price_reason'], 'price_source' => $line['price_source'], 'price_list_id' => $line['price_list_id'], 'price_list_name' => null,
            ];
        }
        $this->quotations[$id] = [
            'id' => $id, 'quotation_number' => $number, 'customer_id' => $customerId, 'customer_name' => 'Customer #' . $customerId, 'source_warehouse_id' => $warehouseId,
            'warehouse_name' => 'Warehouse #' . $warehouseId, 'status' => 'Draft', 'quote_date' => $quoteDate, 'valid_until' => $validUntil, 'notes' => $notes,
            'created_by' => $createdBy, 'created_by_name' => 'User #' . $createdBy, 'sent_at' => null, 'decided_by' => null, 'decided_at' => null, 'rejection_reason' => null,
            'cancelled_by' => null, 'cancelled_at' => null, 'created_at' => date('Y-m-d H:i:s'), 'lines' => $stored,
        ];

        return $id;
    }

    public function find(int $id): ?array
    {
        if (!isset($this->quotations[$id])) {
            return null;
        }
        $quotation = $this->quotations[$id];
        $order = null;
        foreach ($this->orders?->all() ?? [] as $candidate) {
            if ($candidate->quotationId() === $id) {
                $order = $candidate;
            }
        }
        $total = array_sum(array_map(static fn (array $line): int => $line['unit_quantity'] * TaxSettings::cents($line['unit_price']), $quotation['lines']));

        return $quotation + ['sales_order_id' => $order?->id(), 'sales_order_number' => $order?->orderNumber(), 'total' => TaxSettings::decimal($total), 'line_count' => count($quotation['lines'])];
    }

    public function lock(int $id): ?array
    {
        return $this->find($id);
    }

    public function search(array $filters, int $page, int $perPage, ?int $createdBy = null): PaginatedResult
    {
        $rows = [];
        foreach (array_keys($this->quotations) as $id) {
            $row = $this->find($id);
            if ($row === null || ($createdBy !== null && $row['created_by'] !== $createdBy)) {
                continue;
            }
            $status = (string) ($filters['status'] ?? '');
            if ($status !== '' && QuotationStatus::effective($row, $filters['today']) !== $status && !($status === 'Converted' && $row['sales_order_id'] !== null)) {
                continue;
            }
            if (trim((string) ($filters['q'] ?? '')) !== '' && stripos($row['quotation_number'], trim((string) $filters['q'])) === false) {
                continue;
            }
            unset($row['lines']);
            $rows[] = $row;
        }

        return new PaginatedResult(array_slice(array_reverse($rows), ($page - 1) * $perPage, $perPage), count($rows), $page, $perPage);
    }

    public function markSent(int $id): void
    {
        $this->transition($id, ['Draft'], ['status' => 'Sent', 'sent_at' => date('Y-m-d H:i:s')]);
    }

    public function markAccepted(int $id, int $decidedBy): void
    {
        $this->transition($id, ['Sent'], ['status' => 'Accepted', 'decided_by' => $decidedBy, 'decided_at' => date('Y-m-d H:i:s')]);
    }

    public function markRejected(int $id, int $decidedBy, string $reason): void
    {
        $this->transition($id, ['Sent'], ['status' => 'Rejected', 'decided_by' => $decidedBy, 'decided_at' => date('Y-m-d H:i:s'), 'rejection_reason' => $reason]);
    }

    public function markCancelled(int $id, int $cancelledBy): void
    {
        if ($this->find($id)['sales_order_id'] ?? null) {
            throw new PersistenceException('The quotation changed meanwhile; reload and try again.');
        }
        $this->transition($id, ['Draft', 'Sent', 'Accepted'], ['status' => 'Cancelled', 'cancelled_by' => $cancelledBy, 'cancelled_at' => date('Y-m-d H:i:s')]);
    }

    public function today(): string
    {
        return $this->today ?? BusinessDate::today();
    }

    /** @param list<string> $from @param array<string, mixed> $changes */
    private function transition(int $id, array $from, array $changes): void
    {
        if (!isset($this->quotations[$id]) || !in_array($this->quotations[$id]['status'], $from, true)) {
            throw new PersistenceException('The quotation changed meanwhile; reload and try again.');
        }
        $this->quotations[$id] = $changes + $this->quotations[$id];
    }
}
