<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\OperationalQueryRepositoryInterface;

final class InMemoryOperationalQueryRepository implements OperationalQueryRepositoryInterface
{
    /** @param list<array<string, mixed>> $reportRows */
    public function __construct(private readonly array $reportRows = [])
    {
    }

    public function adminDashboard(): array
    {
        return ['inventory_value' => 10000.0, 'low_stock_count' => 1, 'purchase_orders_by_status' => ['Ordered' => 2], 'sales_orders_by_status' => ['PendingApproval' => 1]];
    }

    public function salesDashboard(int $salesUserId): array
    {
        return ['sales_user_id' => $salesUserId, 'sales_orders_by_status' => ['Draft' => 1], 'sales_order_value' => 2000.0];
    }

    public function warehouseDashboard(): array
    {
        return ['po_receipt_queue' => 1, 'so_issue_queue' => 1, 'low_stock_rows' => $this->lowStockRows()];
    }

    public function lowStockRows(): array
    {
        return [['sku' => 'SKU-001', 'product_name' => 'Widget A', 'warehouse_name' => 'Main Warehouse', 'quantity' => 2, 'reorder_point' => 5]];
    }

    public function stockLedgerRows(?string $from, ?string $to): array
    {
        return $this->reportRows;
    }

    public function orderRows(?string $from, ?string $to, ?int $salesUserId): array
    {
        return $this->reportRows;
    }

    public function reportSummary(string $type, ?string $from, ?string $to, ?int $salesUserId): array
    {
        $counts = $distribution = [];
        $received = $issued = 0;
        foreach ($this->reportRows as $row) {
            $group = (string) ($row[$type === 'orders' ? 'Status' : 'Movement'] ?? 'Unknown');
            $bucket = (string) ($row[$type === 'orders' ? 'Type' : 'Warehouse'] ?? 'Unknown');
            $counts[$group] = ($counts[$group] ?? 0) + 1;
            $distribution[$bucket] = ($distribution[$bucket] ?? 0) + 1;
            $received += $group === 'Receipt' ? (int) ($row['Quantity'] ?? 0) : 0;
            $issued += $group === 'Issue' ? (int) ($row['Quantity'] ?? 0) : 0;
        }
        ksort($counts);
        ksort($distribution);
        return ['total' => count($this->reportRows), 'counts' => $counts, 'distribution' => $distribution, 'received' => $received, 'issued' => $issued];
    }

    public function reportPage(string $type, ?string $from, ?string $to, ?int $salesUserId, int $limit, int $offset): array
    {
        return array_slice($this->reportRows, $offset, $limit);
    }

    public function iterateReportRows(string $type, ?string $from, ?string $to, ?int $salesUserId): iterable
    {
        yield from $this->reportRows;
    }

    public function productAvailability(string $sku): ?array
    {
        if ($sku !== 'SKU-001') {
            return null;
        }

        return ['sku' => 'SKU-001', 'name' => 'Widget A', 'total_quantity' => 7, 'warehouses' => [['warehouse' => 'Main Warehouse', 'quantity' => 7]]];
    }
}
