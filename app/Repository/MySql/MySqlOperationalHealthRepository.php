<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use App\Repository\Contract\OperationalHealthRepositoryInterface;
use PDO;
use RuntimeException;
final class MySqlOperationalHealthRepository implements OperationalHealthRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    public function assertReady(): void
    {
        $statement = $this->pdo->prepare('SELECT version FROM schema_versions WHERE version = :version');
        $statement->execute(['version' => 'phase-0']);
        if ($statement->fetchColumn() === false) { throw new RuntimeException('Schema not initialized.'); }
        // Resolve critical table/column names without scanning business rows.
        $this->pdo->query('SELECT id FROM users LIMIT 0');
        $this->pdo->query('SELECT product_id, warehouse_id, quantity FROM product_stocks LIMIT 0');
        $this->pdo->query('SELECT id FROM stock_ledger LIMIT 0');
        $this->pdo->query('SELECT actor_id, request_key, payload_hash, completed FROM operation_requests LIMIT 0');
        $this->pdo->query('SELECT quantity_delta FROM stock_ledger LIMIT 0');
        $this->pdo->query('SELECT purchase_order_id FROM purchase_order_closures LIMIT 0');
        $this->pdo->query('SELECT sales_order_id FROM sales_order_rejections LIMIT 0');
        $this->pdo->query('SELECT id, condition_confirmed FROM inventory_operations LIMIT 0');
        $this->pdo->query('SELECT source_ledger_id, baseline FROM inventory_operation_items LIMIT 0');
        $this->pdo->query('SELECT returned_quantity FROM inventory_return_totals LIMIT 0');
    }
    public function fingerprint(): array
    {
        $result = [];
        $this->pdo->beginTransaction();
        try {
            $tables = $this->pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
            sort($tables, SORT_STRING);
            foreach ($tables as $name) {
                $table = self::identifier((string) $name);
                $columns = $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
                $order = implode(', ', array_map(self::identifier(...), $columns));
                $rows = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
                $hash = hash_init('sha256');
                $count = 0;
                while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $count++;
                }
                $result[(string) $name] = ['rows' => $count, 'sha256' => hash_final($hash)];
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return $result;
    }
    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
