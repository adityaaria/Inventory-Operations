<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\DeliveryNoteRepositoryInterface;
use App\Repository\Contract\InvoiceRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Support\PaginatedResult;

/** Delivery notes for unit tests (D-19, ADR-025). Lot rows carry no ledger id (the in-memory ledger has no link). */
final class InMemoryDeliveryNoteRepository implements DeliveryNoteRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $notes = [];
    private int $nextId = 1;
    private int $nextLineId = 1;
    private ?InvoiceRepositoryInterface $invoices = null;
    /** When set, create() throws it (rollback tests). */
    public ?\Throwable $failOnCreate = null;

    public function __construct(
        private readonly ?SalesOrderRepositoryInterface $orders = null,
        private readonly ?\App\Repository\Contract\ProductRepositoryInterface $products = null,
    ) {
    }

    public function attachInvoices(InvoiceRepositoryInterface $invoices): void
    {
        $this->invoices = $invoices;
    }

    public function nextShipmentNumber(int $salesOrderId): int
    {
        return 1 + count(array_filter($this->notes, static fn (array $note): bool => $note['sales_order_id'] === $salesOrderId));
    }

    public function create(array $note, array $lines): int
    {
        if ($this->failOnCreate !== null) {
            throw $this->failOnCreate;
        }
        foreach ($this->notes as $existing) {
            if ($existing['delivery_number'] === $note['delivery_number'] || ($existing['sales_order_id'] === $note['sales_order_id'] && $existing['shipment_number'] === $note['shipment_number'])) {
                throw new \App\Exception\ValidationException('A record with that unique value already exists.');
            }
        }
        $id = $this->nextId++;
        $stored = [];
        foreach ($lines as $line) {
            $units = array_sum(array_map(static fn (array $lot): int => $lot['quantity'], $line['lots']));
            if ($units !== $line['quantity'] || $line['quantity'] !== $line['unit_quantity'] * $line['unit_factor']) {
                throw new \App\Exception\PersistenceException('Delivery note lots do not add up to the shipped quantity.');
            }
            $product = $this->products?->findById($line['product_id']);
            $stored[] = ['id' => $this->nextLineId++, 'sku' => $product?->sku() ?? '#' . $line['product_id'], 'product_name' => $product?->name() ?? '', 'base_unit' => $product?->unit() ?? ''] + $line;
        }
        $this->notes[$id] = ['id' => $id, 'shipped_at' => $note['shipped_at'] ?? date('Y-m-d H:i:s'), 'origin' => $note['origin'] ?? 'Issue'] + $note + ['lines' => $stored];

        return $id;
    }

    public function find(int $id): ?array
    {
        return isset($this->notes[$id]) ? $this->header($this->notes[$id]) + ['lines' => $this->notes[$id]['lines']] : null;
    }

    public function lock(int $id): ?array
    {
        return $this->find($id);
    }

    public function forSalesOrder(int $salesOrderId): array
    {
        $rows = array_values(array_filter($this->notes, static fn (array $note): bool => $note['sales_order_id'] === $salesOrderId));
        usort($rows, static fn (array $a, array $b): int => $a['shipment_number'] <=> $b['shipment_number']);

        return array_map(fn (array $note): array => $this->header($note), $rows);
    }

    public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult
    {
        $rows = array_map(fn (array $note): array => $this->header($note), array_values($this->notes));
        $term = trim((string) ($filters['q'] ?? ''));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($salesCreatedBy === null || $row['sales_created_by'] === $salesCreatedBy)
            && ($term === '' || stripos($row['delivery_number'] . ' ' . $row['order_number'] . ' ' . $row['ship_to_name'], $term) !== false)
            && self::matchesInvoiced((string) ($filters['invoiced'] ?? ''), $row['invoice_id'] !== null)));
        /** @var list<array<string, mixed>> $rows */
        $rows = array_reverse($rows);

        return new PaginatedResult(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $page, $perPage);
    }

    private static function matchesInvoiced(string $filter, bool $invoiced): bool
    {
        return $filter === '' || ($filter === 'yes') === $invoiced;
    }

    /** @param array<string, mixed> $note @return array<string, mixed> */
    private function header(array $note): array
    {
        $order = $this->orders?->findById((int) $note['sales_order_id']);
        $invoice = $this->invoices?->activeForDeliveryNote((int) $note['id']);
        $header = $note;
        unset($header['lines']);

        return $header + [
            'order_number' => $order?->orderNumber() ?? ('SO #' . $note['sales_order_id']),
            'sales_created_by' => $order?->createdBy() ?? 0,
            'warehouse_name' => 'Warehouse #' . $note['warehouse_id'],
            'warehouse_location' => '',
            'prepared_by_name' => 'User #' . $note['prepared_by'],
            'units' => array_sum(array_map(static fn (array $line): int => $line['quantity'], $note['lines'])),
            'invoice_id' => $invoice['id'] ?? null,
            'invoice_number' => $invoice['invoice_number'] ?? null,
        ];
    }
}
