<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlWarehouseRepository;
use App\Security\AuthContext;
use App\Service\DraftCheckService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DraftCheckIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DraftCheckService $drafts;

    protected function setUp(): void
    {
        \Tests\Support\TestDatabase::reset();
        $this->pdo = \Tests\Support\TestDatabase::connect();
        $this->drafts = new DraftCheckService(new MySqlProductRepository($this->pdo), new MySqlWarehouseRepository($this->pdo), new MySqlStockRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        \Tests\Support\TestDatabase::reset();
    }

    public function testRestoredDraftSeesCurrentStockAndDeactivatedProducts(): void
    {
        $warehouseId = (int) $this->pdo->query('SELECT MIN(id) FROM warehouses WHERE is_active = 1')->fetchColumn();
        $statement = $this->pdo->prepare('SELECT ps.product_id, ps.warehouse_id, ps.quantity FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id WHERE p.is_active = 1 AND ps.warehouse_id = :warehouse ORDER BY ps.product_id LIMIT 2');
        $statement->execute(['warehouse' => $warehouseId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        $warehouse = (int) $rows[0]['warehouse_id'];
        $first = (int) $rows[0]['product_id'];
        $second = (int) $rows[1]['product_id'];
        $sales = new AuthContext(2, 'sales@test', 'Sales');

        $before = $this->drafts->check($sales, 'sales-order', $warehouse, [$first]);
        self::assertSame((int) $rows[0]['quantity'], $before['items'][0]['available']);

        $this->pdo->prepare('UPDATE product_stocks SET quantity = quantity + 3 WHERE product_id = :product AND warehouse_id = :warehouse')->execute(['product' => $first, 'warehouse' => $warehouse]);
        $this->pdo->prepare('UPDATE products SET is_active = 0 WHERE id = :id')->execute(['id' => $second]);
        $after = $this->drafts->check($sales, 'sales-order', $warehouse, [$first, $second]);

        self::assertSame((int) $rows[0]['quantity'] + 3, $after['items'][0]['available']);
        self::assertFalse($after['items'][1]['active']);
        self::assertSame(['id' => $warehouse, 'active' => true], $after['warehouse']);
        self::assertFalse($this->pdo->inTransaction(), 'Draft checks never open a locking transaction.');
    }
}
