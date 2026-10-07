<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\{MySqlProductRepository, MySqlWarehouseRepository, MySqlStockRepository, MySqlStockLedgerRepository, MySqlStockCatalogRepository, MySqlTransactionManager};
use App\Security\AuthContext;
use App\Service\{ProductService, WarehouseService, StockService, MasterDataAuthorizationService, CsvImportService};
use App\Support\ProductInput;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;
use PDO;

final class StockCatalogInitializationIntegrationTest extends TestCase
{
    private PDO $pdo;
    private StockService $stock;
    private MySqlTransactionManager $transactions;
    private ProductService $products;
    private AuthContext $actor;

    protected function setUp(): void
    {
        TestDatabase::reset(); $this->pdo = TestDatabase::connect();
        $this->transactions = new MySqlTransactionManager($this->pdo);
        $this->stock = new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), null, new MySqlStockCatalogRepository($this->pdo), $this->transactions);
        $this->products = new ProductService(new MySqlProductRepository($this->pdo), new MasterDataAuthorizationService(), $this->stock);
        $this->actor = new AuthContext(1,'admin@example.test','Admin');
    }

    public function testProductAndWarehouseCreationInitializeRealZeroRowsWithoutMovements(): void
    {
        $before = $this->number('SELECT COUNT(*) FROM stock_ledger');
        $product = $this->products->create($this->actor, $this->input('ZERO-SKU'));
        self::assertSame($this->number('SELECT COUNT(*) FROM warehouses'), $this->number('SELECT COUNT(*) FROM product_stocks WHERE product_id=? AND quantity=0', [$product->id()]));
        $warehouse = (new WarehouseService(new MySqlWarehouseRepository($this->pdo), new MasterDataAuthorizationService(), $this->stock))->create($this->actor, 'Zero warehouse', 'Test');
        self::assertSame($this->number('SELECT COUNT(*) FROM products'), $this->number('SELECT COUNT(*) FROM product_stocks WHERE warehouse_id=? AND quantity=0', [$warehouse->id()]));
        self::assertSame($before, $this->number('SELECT COUNT(*) FROM stock_ledger'));
    }

    public function testProductImportRollsBackMasterAndZeroRowsTogether(): void
    {
        $before = $this->number('SELECT COUNT(*) FROM product_stocks');
        $imports = new CsvImportService($this->transactions);
        try {
            $imports->import([['sku'=>'IMPORT-GOOD'], ['sku'=>'']], function (array $row): void { $this->products->create($this->actor, $this->input($row['sku'])); });
            self::fail('Import must fail.');
        } catch (\InvalidArgumentException) {}
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM products WHERE sku='IMPORT-GOOD'"));
        self::assertSame($before, $this->number('SELECT COUNT(*) FROM product_stocks'));
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testInitializationFailureRollsBackProductAndEarlierZeroRows(): void
    {
        $catalog = new class($this->pdo) implements \App\Repository\Contract\StockCatalogRepositoryInterface {
            public function __construct(private readonly PDO $pdo) {}
            public function lockCreation(): void { (new MySqlStockCatalogRepository($this->pdo))->lockCreation(); }
            public function productIds(): array { return []; }
            public function warehouseIds(): array { return [1, 999999]; }
        };
        $stock = new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), null, $catalog, $this->transactions);
        $products = new ProductService(new MySqlProductRepository($this->pdo), new MasterDataAuthorizationService(), $stock);
        $before = $this->number('SELECT COUNT(*) FROM product_stocks');
        try { $products->create($this->actor, $this->input('ROLLBACK-ZERO')); self::fail('Foreign-key failure must propagate.'); }
        catch (\PDOException) {}
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM products WHERE sku='ROLLBACK-ZERO'"));
        self::assertSame($before, $this->number('SELECT COUNT(*) FROM product_stocks'));
    }

    public function testHistoricalPairRepairIsIdempotentAndPreservesBalancesAndLedger(): void
    {
        $rows = $this->pdo->query('SELECT product_id,warehouse_id,quantity FROM product_stocks ORDER BY product_id,warehouse_id')->fetchAll(PDO::FETCH_ASSOC);
        $ledger = $this->number('SELECT COUNT(*) FROM stock_ledger');
        $this->stock->initializeCatalog(); $this->stock->initializeCatalog();
        self::assertSame($this->number('SELECT COUNT(*) FROM products') * $this->number('SELECT COUNT(*) FROM warehouses'), $this->number('SELECT COUNT(*) FROM product_stocks'));
        foreach ($rows as $row) self::assertSame((int)$row['quantity'], $this->number('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?', [(int)$row['product_id'],(int)$row['warehouse_id']]));
        self::assertSame($ledger, $this->number('SELECT COUNT(*) FROM stock_ledger'));
    }

    private function input(string $sku): ProductInput { return new ProductInput($sku,'Zero test','pcs',10.0,20.0,5,1); }
    private function number(string $sql, array $params=[]): int { $statement=$this->pdo->prepare($sql);$statement->execute($params);return (int)$statement->fetchColumn(); }
}
