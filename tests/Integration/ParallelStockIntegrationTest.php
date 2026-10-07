<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Repository\MySql\{MySqlProductRepository, MySqlSalesOrderRepository, MySqlPurchaseOrderRepository, MySqlStockRepository, MySqlStockLedgerRepository, MySqlAuditLogRepository};
use App\Security\AuthContext;
use App\Service\{SalesOrderService, PurchaseOrderService, StockService};
use App\Support\ProductInput;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class ParallelStockIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private MySqlProductRepository $products;
    private AuthContext $actor;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo),new \App\Repository\MySql\MySqlStockCatalogRepository($this->pdo),new \App\Repository\MySql\MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        $this->actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    }

    public function testTwoProcessesIssueSameOrderOnlyOnce(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 'SO-PARALLEL-SAME', 1, 1, [['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);
        $service->submit($this->actor, $order->id()); $service->approve($this->actor, $order->id());
        $this->race($product, [['operation'=>'issue','id'=>$order->id()], ['operation'=>'issue','id'=>$order->id()]]);
        self::assertSame(1, $this->balances->quantity($product, 1));
        self::assertSame('Fulfilled', $orders->findById($order->id())->status());
        self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id()));
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.issue' AND status='success' AND entity_id=?", [$order->id()]));
    }

    public function testConcurrentDuplicatePartialReceiptBothSucceedWithOneMovement(): void
    {
        $product=$this->fixtureProduct(); $orders=new MySqlPurchaseOrderRepository($this->pdo);
        $service=new PurchaseOrderService($orders,$this->products,[1=>new Supplier(1,'Supplier','','','',true)],[1=>new Warehouse(1,'Warehouse','',true)],$this->stock);
        $order=$service->createDraft($this->actor,'PO-IDEM-RACE',1,1,[['product_id'=>$product,'quantity'=>10,'purchase_price'=>10.0]]);$service->markOrdered($this->actor,$order->id());
        $job=['operation'=>'receive','id'=>$order->id(),'item_id'=>$order->items()[0]->id(),'quantity'=>3,'key'=>str_repeat('a',32)];
        $this->race($product,[$job,$job],false,true);
        self::assertSame(8,$this->balances->quantity($product,1));self::assertSame(3,$orders->findById($order->id())->items()[0]->receivedQuantity());
        self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id()));
    }

    public function testConcurrentDuplicateIssueBothSucceedWithOneMovement(): void
    {
        $product=$this->fixtureProduct();[$service,$orders]=$this->sales();
        $order=$service->createDraft($this->actor,'SO-IDEM-RACE',1,1,[['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);$service->submit($this->actor,$order->id());$service->approve($this->actor,$order->id());
        $job=['operation'=>'issue','id'=>$order->id(),'key'=>str_repeat('b',32)];$this->race($product,[$job,$job],false,true);
        self::assertSame(1,$this->balances->quantity($product,1));self::assertSame('Fulfilled',$orders->findById($order->id())->status());
        self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('SO',$order->id()));
    }

    private function business(): array
    {
        $user=(new \App\Repository\MySql\MySqlUserRepository($this->pdo))->create('Independent reviewer','business-reviewer@example.test',password_hash('password',PASSWORD_DEFAULT),'Admin',true);
        return [new \App\Service\BusinessOperationService(new \App\Repository\MySql\MySqlBusinessOperationRepository($this->pdo),$this->stock,new MySqlAuditLogRepository($this->pdo)),new AuthContext($user->id(),$user->email(),'Admin')];
    }

    public function testConcurrentTransferReplayBothSucceedWithOnePairOfMovements(): void
    {
        $product=$this->fixtureProduct();[$business,$reviewer]=$this->business();$id=$business->propose($this->actor,'Transfer',1,2,'Move',[['product_id'=>$product,'quantity'=>3]]);$business->decide($reviewer,$id,'Approved','Checked');
        $job=['operation'=>'inventory_post','id'=>$id];$this->race($product,[$job,$job],false,true);
        self::assertSame(2,$this->balances->quantity($product,1));self::assertSame(3,$this->balances->quantity($product,2));self::assertCount(2,(new MySqlStockLedgerRepository($this->pdo))->forReference('TRANSFER',$id));
    }

    public function testOppositeTransfersUseDeterministicPairLocksAndConserveTotal(): void
    {
        $product=$this->fixtureProduct();$this->stock->receive(2,[new \App\Service\StockMovement($product,4)],1,'TEST',2);[$business,$reviewer]=$this->business();$jobs=[];
        foreach([[1,2],[2,1]] as [$source,$destination]){$id=$business->propose($this->actor,'Transfer',$source,$destination,'Opposite move',[['product_id'=>$product,'quantity'=>3]]);$business->decide($reviewer,$id,'Approved','Checked');$jobs[]=['operation'=>'inventory_post','id'=>$id];}
        $this->race($product,$jobs,false,true);self::assertSame(5,$this->balances->quantity($product,1));self::assertSame(4,$this->balances->quantity($product,2));
    }

    public function testConcurrentCustomerReturnsCannotExceedOriginalIssue(): void
    {
        $product=$this->fixtureProduct();[$sales,$orders]=$this->sales();$order=$sales->createDraft($this->actor,'SO-RETURN-RACE',1,1,[['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);$sales->submit($this->actor,$order->id());$sales->approve($this->actor,$order->id());$sales->issue($this->actor,$order->id());
        $source=(new MySqlStockLedgerRepository($this->pdo))->forReference('SO',$order->id())[0]->id();[$business,$reviewer]=$this->business();$jobs=[];
        foreach([1,2] as $unused){$id=$business->propose($this->actor,'CustomerReturn',0,null,'Returned goods',[['quantity'=>3,'source_ledger_id'=>$source,'fit_for_stock'=>true]]);$business->decide($reviewer,$id,'Approved','Checked');$jobs[]=['operation'=>'inventory_post','id'=>$id];}
        $this->race($product,$jobs);self::assertSame(4,$this->balances->quantity($product,1));self::assertSame(3,$this->sqlCount('SELECT returned_quantity FROM inventory_return_totals WHERE source_ledger_id=?',[$source]));self::assertSame('Fulfilled',$orders->findById($order->id())->status());
    }

    public function testDifferentOrdersCompetingForSameStockCannotOversell(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $ids = [];
        foreach (['A', 'B'] as $suffix) {
            $order = $service->createDraft($this->actor, 'SO-PARALLEL-' . $suffix, 1, 1, [['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);
            $service->submit($this->actor, $order->id()); $service->approve($this->actor, $order->id());
            $ids[] = $order->id();
        }
        $this->race($product, array_map(static fn (int $id): array => ['operation'=>'issue','id'=>$id], $ids));
        self::assertSame(1, $this->balances->quantity($product, 1));
        $statuses = array_map(static fn (int $id): string => $orders->findById($id)->status(), $ids);
        sort($statuses); self::assertSame(['Approved', 'Fulfilled'], $statuses);
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM stock_ledger WHERE product_id=? AND movement_type='Issue'", [$product]));
    }

    public function testConcurrentPartialReceiptsCannotExceedOrderRemainder(): void
    {
        $product = $this->fixtureProduct();
        $orders = new MySqlPurchaseOrderRepository($this->pdo);
        $service = new PurchaseOrderService($orders, $this->products, [1=>new Supplier(1,'Supplier','','','',true)], [1=>new Warehouse(1,'Warehouse','',true)], $this->stock);
        $order = $service->createDraft($this->actor, 'PO-PARALLEL', 1, 1, [['product_id'=>$product,'quantity'=>5,'purchase_price'=>10.0]]);
        $service->markOrdered($this->actor, $order->id());
        $job = ['operation'=>'receive','id'=>$order->id(),'item_id'=>$order->items()[0]->id(),'quantity'=>3];
        $this->race($product, [$job, $job]);
        self::assertSame(8, $this->balances->quantity($product, 1));
        $updated = $orders->findById($order->id());
        self::assertSame('PartiallyReceived', $updated->status());
        self::assertSame(3, $updated->items()[0]->receivedQuantity());
        self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('PO', $order->id()));
    }

    public function testConcurrentProductAndWarehouseCreationInitializesTheirIntersection(): void
    {
        $before = $this->sqlCount('SELECT COUNT(*) FROM stock_ledger', []);
        $this->race(0, [['operation'=>'catalog_product'], ['operation'=>'catalog_warehouse']], true);
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM product_stocks ps INNER JOIN products p ON p.id=ps.product_id INNER JOIN warehouses w ON w.id=ps.warehouse_id WHERE p.sku='PARALLEL-CATALOG' AND w.name='Parallel warehouse' AND ps.quantity=0", []));
        self::assertSame($before, $this->sqlCount('SELECT COUNT(*) FROM stock_ledger', []));
    }

    private function fixtureProduct(): int
    {
        $product = $this->products->create(new ProductInput('PARALLEL-SKU','Parallel stock','pcs',10.0,20.0,1,1), true)->id();
        $this->stock->initializeProduct($product);
        $this->stock->receive(1, [new \App\Service\StockMovement($product,5)], 1, 'TEST', 1);
        return $product;
    }

    private function sales(): array
    {
        $orders = new MySqlSalesOrderRepository($this->pdo);
        return [new SalesOrderService($orders, $this->products, [1=>new Customer(1,'Customer','','','',true)], [1=>new Warehouse(1,'Warehouse','',true)], $this->stock), $orders];
    }

    /** @param list<array<string, int|string>> $jobs */
    private function race(int $product, array $jobs, bool $catalogCreation = false, bool $replay = false): void
    {
        $directory = sys_get_temp_dir() . '/inventory-parallel-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        $this->pdo->beginTransaction();
        if ($catalogCreation) (new \App\Repository\MySql\MySqlStockCatalogRepository($this->pdo))->lockCreation();
        else $this->balances->lockByProductWarehouse($product, 1);
        try {
            foreach ($jobs as $index => $job) {
                file_put_contents($directory . '/' . $index . '.json', json_encode($job, JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/concurrency-worker.php', $directory . '/' . $index . '.json', $directory . '/' . $index . '.ready', $directory . '/gate'], [0=>['pipe','r'],1=>['file',$directory . '/' . $index . '.out','w'],2=>['file',$directory . '/' . $index . '.err','w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                fclose($pipes[0]); $processes[] = $process;
            }
            $this->awaitFiles($directory, '.ready');
            touch($directory . '/gate');
            $this->awaitFiles($directory, '.ready.started');
            // Both independently connected workers begin their workflows while stock is locked.
            usleep(50000);
            $this->pdo->commit();
            $codes = [];
            foreach ($processes as $index => $process) {
                $codes[] = proc_close($process);
                self::assertSame('', (string) file_get_contents($directory . '/' . $index . '.err'));
            }
            $processes = [];
            sort($codes); self::assertSame(($catalogCreation || $replay) ? [0, 0] : [0, 2], $codes, 'Catalog creations both commit; competing movements commit only once.');
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    private function awaitFiles(string $directory, string $suffix): void
    {
        $deadline = microtime(true) + 10;
        while (!is_file($directory . '/0' . $suffix) || !is_file($directory . '/1' . $suffix)) {
            if (microtime(true) > $deadline) self::fail('Workers did not reach the concurrency barrier.');
            usleep(1000);
        }
    }

    private function sqlCount(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        return (int) $statement->fetchColumn();
    }
}
