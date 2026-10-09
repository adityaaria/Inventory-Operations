<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\PurchaseOrder;
use App\Entity\SalesOrder;
use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Support\OrderSearchCriteria;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** Covers purchase/sales order repository listing, filtering, guards and atomic draft creation against MySQL. */
final class MySqlOrderRepositoryCoverageIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connect();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function testPurchaseOrderAllReturnsEveryOrderNewestFirstWithItems(): void
    {
        $orders = (new MySqlPurchaseOrderRepository($this->pdo))->all();
        self::assertCount((int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn(), $orders);
        $expectedIds = array_map('intval', $this->pdo->query('SELECT id FROM purchase_orders ORDER BY order_date DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($expectedIds, array_map(static fn (PurchaseOrder $o): int => $o->id(), $orders));
        $itemCount = array_sum(array_map(static fn (PurchaseOrder $o): int => count($o->items()), $orders));
        self::assertSame((int) $this->pdo->query('SELECT COUNT(*) FROM purchase_order_items')->fetchColumn(), $itemCount);
    }

    public function testPurchaseOrderSearchFiltersByTermAndStatus(): void
    {
        $row = $this->pdo->query("SELECT po.order_number, s.name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.status = 'Received' ORDER BY po.id LIMIT 1")->fetch();
        $supplier = (string) $row['name'];
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE (po.order_number LIKE :t1 OR s.name LIKE :t2) AND po.status = 'Received'");
        $statement->execute(['t1' => "%{$supplier}%", 't2' => "%{$supplier}%"]);
        $expected = (int) $statement->fetchColumn();

        $result = (new MySqlPurchaseOrderRepository($this->pdo))->search(OrderSearchCriteria::fromArray(['q' => $supplier, 'status' => 'Received', 'sort' => 'order_number', 'direction' => 'asc']));
        self::assertGreaterThan(0, $expected);
        self::assertSame($expected, $result->total());
        $numbers = [];
        foreach ($result->items() as $order) {
            self::assertSame('Received', $order->status());
            $numbers[] = $order->orderNumber();
        }
        $sorted = $numbers;
        sort($sorted);
        self::assertSame($sorted, $numbers);

        $exact = (new MySqlPurchaseOrderRepository($this->pdo))->search(OrderSearchCriteria::fromArray(['q' => (string) $row['order_number']]));
        self::assertGreaterThanOrEqual(1, $exact->total());
        self::assertContains((string) $row['order_number'], array_map(static fn (PurchaseOrder $o): string => $o->orderNumber(), $exact->items()));
    }

    public function testPurchaseOrderLockRequiresTransactionAndCancelOnlyAffectsOpenOrders(): void
    {
        $repository = new MySqlPurchaseOrderRepository($this->pdo);
        try {
            $repository->lockById(1);
            self::fail('Locking outside a transaction must fail.');
        } catch (PersistenceException $exception) {
            self::assertSame('Source order locks require a transaction.', $exception->getMessage());
        }

        $draft = $repository->createDraft('PO-COV-0001', 1, 1, 1, [['product_id' => 1, 'quantity' => 5, 'purchase_price' => 10.0]]);
        $repository->cancel($draft->id());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $repository->findById($draft->id())?->status());

        $received = (int) $this->pdo->query("SELECT id FROM purchase_orders WHERE status = 'Received' ORDER BY id LIMIT 1")->fetchColumn();
        $repository->cancel($received);
        self::assertSame('Received', $repository->findById($received)?->status());
    }

    public function testPurchaseOrderDraftFailureRollsBackHeaderAndMapsError(): void
    {
        $repository = new MySqlPurchaseOrderRepository($this->pdo);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
        try {
            $repository->createDraft('PO-COV-FAIL', 1, 1, 1, [
                ['product_id' => 1, 'quantity' => 5, 'purchase_price' => 10.0],
                ['product_id' => 1, 'quantity' => 3, 'purchase_price' => 10.0],
            ]);
            self::fail('Duplicate product lines must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('A record with that unique value already exists.', $exception->getMessage());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE order_number = 'PO-COV-FAIL'")->fetchColumn());
    }

    public function testSalesOrderAllAndForCreatorReturnOrdersWithItems(): void
    {
        $repository = new MySqlSalesOrderRepository($this->pdo);
        $orders = $repository->all();
        self::assertCount((int) $this->pdo->query('SELECT COUNT(*) FROM sales_orders')->fetchColumn(), $orders);
        $expectedIds = array_map('intval', $this->pdo->query('SELECT id FROM sales_orders ORDER BY order_date DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($expectedIds, array_map(static fn (SalesOrder $o): int => $o->id(), $orders));

        $creator = (int) $this->pdo->query('SELECT created_by FROM sales_orders ORDER BY id LIMIT 1')->fetchColumn();
        $statement = $this->pdo->prepare('SELECT id FROM sales_orders WHERE created_by = :creator ORDER BY order_date DESC, id DESC');
        $statement->execute(['creator' => $creator]);
        $own = $repository->forCreator($creator);
        self::assertSame(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), array_map(static fn (SalesOrder $o): int => $o->id(), $own));
        foreach ($own as $order) {
            self::assertSame($creator, $order->createdBy());
            self::assertNotSame([], $order->items());
        }
        self::assertSame([], $repository->forCreator(999999));
    }

    public function testSalesOrderSearchFiltersByTermStatusAndCreator(): void
    {
        $row = $this->pdo->query("SELECT so.created_by, c.name FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE so.status = 'Fulfilled' ORDER BY so.id LIMIT 1")->fetch();
        $customer = (string) $row['name'];
        $creator = (int) $row['created_by'];
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE (so.order_number LIKE :t1 OR c.name LIKE :t2) AND so.status = 'Fulfilled' AND so.created_by = :creator");
        $statement->execute(['t1' => "%{$customer}%", 't2' => "%{$customer}%", 'creator' => $creator]);
        $expected = (int) $statement->fetchColumn();

        $result = (new MySqlSalesOrderRepository($this->pdo))->search(OrderSearchCriteria::fromArray(['q' => $customer, 'status' => 'Fulfilled']), $creator);
        self::assertGreaterThan(0, $expected);
        self::assertSame($expected, $result->total());
        foreach ($result->items() as $order) {
            self::assertSame('Fulfilled', $order->status());
            self::assertSame($creator, $order->createdBy());
        }
        self::assertSame(0, (new MySqlSalesOrderRepository($this->pdo))->search(OrderSearchCriteria::fromArray(['q' => 'no-such-order-zzz']))->total());
    }

    public function testFailedOrderListQueriesAreReportedAsPersistenceErrors(): void
    {
        // A real session in silent error mode whose order tables are shadowed by incompatible temporary tables, so PDO::query() returns false.
        $pdo = TestDatabase::connect();
        $pdo->exec('CREATE TEMPORARY TABLE purchase_orders (unrelated INT)');
        $pdo->exec('CREATE TEMPORARY TABLE sales_orders (unrelated INT)');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        foreach ([
            'Unable to query purchase orders.' => static fn (): array => (new MySqlPurchaseOrderRepository($pdo))->all(),
            'Unable to query sales orders.' => static fn (): array => (new MySqlSalesOrderRepository($pdo))->all(),
        ] as $message => $call) {
            try {
                $call();
                self::fail("Expected failure: {$message}");
            } catch (PersistenceException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testSalesOrderLockRequiresTransactionAndDraftFailureRollsBack(): void
    {
        $repository = new MySqlSalesOrderRepository($this->pdo);
        try {
            $repository->lockById(1);
            self::fail('Locking outside a transaction must fail.');
        } catch (PersistenceException $exception) {
            self::assertSame('Source order locks require a transaction.', $exception->getMessage());
        }

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM sales_orders')->fetchColumn();
        try {
            $repository->createDraft('SO-COV-FAIL', 1, 1, 2, [['product_id' => 999999, 'quantity' => 1, 'selling_price' => 1.0]]);
            self::fail('Unknown product must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('The selected related record is invalid or in use.', $exception->getMessage());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM sales_orders')->fetchColumn());
    }
}
