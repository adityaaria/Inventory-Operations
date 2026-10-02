<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Customer;
use App\Entity\ProductStock;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\StockLedgerEntry;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use PHPUnit\Framework\TestCase;

final class EntityGettersTest extends TestCase
{
    public function testCustomerGetters(): void
    {
        $customer = new Customer(1, 'Demo Customer', 'customer@example.test', '021-0001', 'Jl. Raya 1', true);

        self::assertSame(1, $customer->id());
        self::assertSame('Demo Customer', $customer->name());
        self::assertSame('customer@example.test', $customer->email());
        self::assertSame('021-0001', $customer->phone());
        self::assertSame('Jl. Raya 1', $customer->address());
        self::assertTrue($customer->isActive());
    }

    public function testSupplierGetters(): void
    {
        $supplier = new Supplier(1, 'Demo Supplier', 'supplier@example.test', '021-0002', 'Jl. Raya 2', false);

        self::assertSame(1, $supplier->id());
        self::assertSame('Demo Supplier', $supplier->name());
        self::assertSame('supplier@example.test', $supplier->email());
        self::assertSame('021-0002', $supplier->phone());
        self::assertSame('Jl. Raya 2', $supplier->address());
        self::assertFalse($supplier->isActive());
    }

    public function testWarehouseGetters(): void
    {
        $warehouse = new Warehouse(1, 'Main Warehouse', 'Jakarta', true);

        self::assertSame(1, $warehouse->id());
        self::assertSame('Main Warehouse', $warehouse->name());
        self::assertSame('Jakarta', $warehouse->location());
        self::assertTrue($warehouse->isActive());
    }

    public function testProductStockGettersAndLowStockThreshold(): void
    {
        $lowStock = new ProductStock(10, 1, 'SKU-001', 'Widget A', 'Main Warehouse', 3, 5);
        $normalStock = new ProductStock(10, 1, 'SKU-001', 'Widget A', 'Main Warehouse', 10, 5);

        self::assertSame(10, $lowStock->productId());
        self::assertSame(1, $lowStock->warehouseId());
        self::assertSame('SKU-001', $lowStock->sku());
        self::assertSame('Widget A', $lowStock->productName());
        self::assertSame('Main Warehouse', $lowStock->warehouseName());
        self::assertSame(3, $lowStock->quantity());
        self::assertSame(5, $lowStock->reorderPoint());
        self::assertTrue($lowStock->isLowStock());
        self::assertFalse($normalStock->isLowStock());
    }

    public function testStockLedgerEntryGetters(): void
    {
        $entry = new StockLedgerEntry(1, 10, 1, 'Receipt', 5, 'PO', 99, 7);

        self::assertSame(1, $entry->id());
        self::assertSame(10, $entry->productId());
        self::assertSame(1, $entry->warehouseId());
        self::assertSame('Receipt', $entry->movementType());
        self::assertSame(5, $entry->quantity());
        self::assertSame('PO', $entry->referenceType());
        self::assertSame(99, $entry->referenceId());
        self::assertSame(7, $entry->performedBy());
    }

    public function testPurchaseOrderItemRemainingQuantityAndWither(): void
    {
        $item = new PurchaseOrderItem(1, 100, 10, 5, 2, 1000.0);

        self::assertSame(1, $item->id());
        self::assertSame(100, $item->purchaseOrderId());
        self::assertSame(10, $item->productId());
        self::assertSame(5, $item->quantity());
        self::assertSame(2, $item->receivedQuantity());
        self::assertSame(1000.0, $item->purchasePrice());
        self::assertSame(3, $item->remainingQuantity());

        $received = $item->withReceivedQuantity(5);
        self::assertSame(5, $received->receivedQuantity());
        self::assertSame(0, $received->remainingQuantity());
        self::assertSame(1, $received->id());
    }

    public function testPurchaseOrderIsFullyReceivedAndWithStatus(): void
    {
        $openItem = new PurchaseOrderItem(1, 100, 10, 5, 2, 1000.0);
        $closedItem = new PurchaseOrderItem(2, 100, 11, 5, 5, 1000.0);

        $withOpenItem = new PurchaseOrder(100, 'PO-001', 1, 1, PurchaseOrder::STATUS_ORDERED, '2026-01-01', 1, [$openItem]);
        $withClosedItems = new PurchaseOrder(100, 'PO-001', 1, 1, PurchaseOrder::STATUS_ORDERED, '2026-01-01', 1, [$closedItem]);
        $withNoItems = new PurchaseOrder(100, 'PO-001', 1, 1, PurchaseOrder::STATUS_DRAFT, '2026-01-01', 1, []);

        self::assertSame(100, $withOpenItem->id());
        self::assertSame('PO-001', $withOpenItem->orderNumber());
        self::assertSame(1, $withOpenItem->supplierId());
        self::assertSame(1, $withOpenItem->destinationWarehouseId());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $withOpenItem->status());
        self::assertSame('2026-01-01', $withOpenItem->orderDate());
        self::assertSame(1, $withOpenItem->createdBy());
        self::assertCount(1, $withOpenItem->items());

        self::assertFalse($withOpenItem->isFullyReceived());
        self::assertTrue($withClosedItems->isFullyReceived());
        self::assertFalse($withNoItems->isFullyReceived());

        $received = $withClosedItems->withStatus(PurchaseOrder::STATUS_RECEIVED);
        self::assertSame(PurchaseOrder::STATUS_RECEIVED, $received->status());
        self::assertSame(100, $received->id());
    }

    public function testSalesOrderItemGetters(): void
    {
        $item = new SalesOrderItem(1, 200, 10, 2, 2000.0);

        self::assertSame(1, $item->id());
        self::assertSame(200, $item->salesOrderId());
        self::assertSame(10, $item->productId());
        self::assertSame(2, $item->quantity());
        self::assertSame(2000.0, $item->sellingPrice());
    }

    public function testSalesOrderGettersAndWithStatus(): void
    {
        $item = new SalesOrderItem(1, 200, 10, 2, 2000.0);
        $order = new SalesOrder(200, 'SO-001', 1, 1, SalesOrder::STATUS_DRAFT, '2026-01-01', 7, null, null, [$item]);

        self::assertSame(200, $order->id());
        self::assertSame('SO-001', $order->orderNumber());
        self::assertSame(1, $order->customerId());
        self::assertSame(1, $order->sourceWarehouseId());
        self::assertSame(SalesOrder::STATUS_DRAFT, $order->status());
        self::assertSame('2026-01-01', $order->orderDate());
        self::assertSame(7, $order->createdBy());
        self::assertNull($order->approvedBy());
        self::assertNull($order->approvedAt());
        self::assertCount(1, $order->items());

        $approved = $order->withStatus(SalesOrder::STATUS_APPROVED, 1, '2026-01-02');
        self::assertSame(SalesOrder::STATUS_APPROVED, $approved->status());
        self::assertSame(1, $approved->approvedBy());
        self::assertSame('2026-01-02', $approved->approvedAt());

        $keepsPreviousApproval = $approved->withStatus(SalesOrder::STATUS_FULFILLED);
        self::assertSame(1, $keepsPreviousApproval->approvedBy());
        self::assertSame('2026-01-02', $keepsPreviousApproval->approvedAt());
    }
}
