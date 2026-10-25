<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\StockLot;
use App\Repository\Contract\StockRepositoryInterface;
use PDO;
use RuntimeException;

final class MySqlStockRepository implements StockRepositoryInterface
{
    private const LOT_COLUMNS = 'l.id, l.product_id, l.warehouse_id, l.lot_seq, l.lot_code, l.expiry_date, l.received_at, l.unit_cost, l.quantity, l.opening_quantity, l.source_type, l.source_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function lockByProductWarehouse(int $productId, int $warehouseId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT quantity FROM product_stocks
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id
             FOR UPDATE'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity)
             VALUES (:product_id, :warehouse_id, 0)'
        );
        $insert->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);

        // INSERT already holds the new row's exclusive lock until this transaction ends.
    }

    public function increment(int $productId, int $warehouseId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks
             SET quantity = quantity + :quantity
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity]);
    }

    public function decrement(int $productId, int $warehouseId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks
             SET quantity = quantity - :quantity
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity]);
    }

    public function quantity(int $productId, int $warehouseId): int
    {
        return $this->column('quantity', $productId, $warehouseId);
    }

    public function reservedQuantity(int $productId, int $warehouseId): int
    {
        return $this->column('reserved_quantity', $productId, $warehouseId);
    }

    public function reserve(int $productId, int $warehouseId, int $quantity): void
    {
        $this->update('reserved_quantity = reserved_quantity + :quantity', $productId, $warehouseId, $quantity);
    }

    public function release(int $productId, int $warehouseId, int $quantity): void
    {
        $this->update('reserved_quantity = reserved_quantity - :quantity', $productId, $warehouseId, $quantity);
    }

    public function issueReserved(int $productId, int $warehouseId, int $quantity): void
    {
        // One statement: the CHECK (reserved_quantity <= quantity) is evaluated on the final row, never in between.
        $this->update('quantity = quantity - :quantity, reserved_quantity = reserved_quantity - :reserved', $productId, $warehouseId, $quantity, true);
    }

    public function expiredQuantity(int $productId, int $warehouseId): int
    {
        // Locking read inside a transaction (after the pair lock) so the sum is current, like quantity().
        if ($this->pdo->inTransaction()) {
            $sum = 0;
            foreach ($this->lockLots($productId, $warehouseId) as $lot) {
                $sum += $lot->isExpired() ? $lot->quantity() : 0;
            }

            return $sum;
        }
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(l.quantity), 0) FROM stock_lots l
             WHERE l.product_id = :product_id AND l.warehouse_id = :warehouse_id AND l.expiry_date IS NOT NULL AND l.expiry_date < ' . ExpirySql::today()
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);

        return (int) $statement->fetchColumn();
    }

    public function lockLots(int $productId, int $warehouseId): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Lot locks require a transaction.');
        }
        $count = $this->lotCount($productId, $warehouseId);
        if ($count === 0) {
            return [];
        }
        // Point lookups on UNIQUE(product_id, warehouse_id, lot_seq): record locks only, no gap locks (ADR-023).
        $placeholders = implode(',', array_fill(0, $count, '?'));
        $statement = $this->pdo->prepare(
            'SELECT ' . self::LOT_COLUMNS . ', (l.expiry_date IS NOT NULL AND l.expiry_date < ' . ExpirySql::today() . ') AS expired
             FROM stock_lots l FORCE INDEX (uq_stock_lots_seq)
             WHERE l.product_id = ? AND l.warehouse_id = ? AND l.lot_seq IN (' . $placeholders . ')
             FOR UPDATE'
        );
        $statement->execute([$productId, $warehouseId, ...range(1, $count)]);

        return StockLot::fefo(array_map(static fn (array $row): StockLot => self::hydrateLot($row), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function createLot(int $productId, int $warehouseId, string $code, ?string $expiryDate, string $unitCost, string $sourceType, ?int $sourceId): StockLot
    {
        $sequence = $this->lotCount($productId, $warehouseId) + 1;
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_lots (product_id, warehouse_id, lot_seq, lot_code, expiry_date, unit_cost, quantity, source_type, source_id)
             VALUES (:product_id, :warehouse_id, :lot_seq, :lot_code, :expiry_date, :unit_cost, 0, :source_type, :source_id)'
        );
        $insert->execute([
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'lot_seq' => $sequence, 'lot_code' => $code,
            'expiry_date' => $expiryDate, 'unit_cost' => $unitCost, 'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $update = $this->pdo->prepare('UPDATE product_stocks SET lot_count = :lot_count WHERE product_id = :product_id AND warehouse_id = :warehouse_id');
        $update->execute(['lot_count' => $sequence, 'product_id' => $productId, 'warehouse_id' => $warehouseId]);
        $lot = $this->findLot($id) ?? throw new RuntimeException('Lot is missing after insert.');

        return $lot;
    }

    public function changeLotQuantity(int $lotId, int $delta): void
    {
        $statement = $this->pdo->prepare('UPDATE stock_lots SET quantity = quantity + :delta WHERE id = :id');
        $statement->execute(['delta' => $delta, 'id' => $lotId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Stock lot is missing.');
        }
    }

    public function findLot(int $lotId): ?StockLot
    {
        $statement = $this->pdo->prepare('SELECT ' . self::LOT_COLUMNS . ', (l.expiry_date IS NOT NULL AND l.expiry_date < ' . ExpirySql::today() . ') AS expired FROM stock_lots l WHERE l.id = :id');
        $statement->execute(['id' => $lotId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrateLot($row) : null;
    }

    public function today(): string
    {
        $statement = $this->pdo->query('SELECT ' . ExpirySql::today());

        return $statement === false ? \App\Support\BusinessDate::today() : (string) $statement->fetchColumn();
    }

    public function tracksExpiry(int $productId): bool
    {
        $statement = $this->pdo->prepare('SELECT tracks_expiry FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function purchaseCost(int $productId): string
    {
        $statement = $this->pdo->prepare('SELECT purchase_price FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);
        $value = $statement->fetchColumn();

        return number_format((float) ($value === false ? 0 : $value), 2, '.', '');
    }

    private function lotCount(int $productId, int $warehouseId): int
    {
        $statement = $this->pdo->prepare('SELECT lot_count FROM product_stocks WHERE product_id = :product_id AND warehouse_id = :warehouse_id' . ($this->pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private static function hydrateLot(array $row): StockLot
    {
        return new StockLot(
            (int) $row['id'], (int) $row['product_id'], (int) $row['warehouse_id'], (int) $row['lot_seq'], (string) $row['lot_code'],
            $row['expiry_date'] === null ? null : (string) $row['expiry_date'], (string) $row['received_at'], (string) $row['unit_cost'],
            (int) $row['quantity'], (bool) $row['expired'], (int) $row['opening_quantity'], (string) $row['source_type'],
            $row['source_id'] === null ? null : (int) $row['source_id'],
        );
    }

    /** @param 'quantity'|'reserved_quantity' $column */
    private function column(string $column, int $productId, int $warehouseId): int
    {
        // Locking read inside a transaction: a plain Repeatable Read snapshot could predate a stock lock we waited for.
        $statement = $this->pdo->prepare(
            'SELECT ' . $column . ' AS value FROM product_stocks WHERE product_id = :product_id AND warehouse_id = :warehouse_id' . ($this->pdo->inTransaction() ? ' FOR UPDATE' : '')
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) $row['value'] : 0;
    }

    private function update(string $assignments, int $productId, int $warehouseId, int $quantity, bool $alsoReserved = false): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks SET ' . $assignments . ' WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
        );
        $params = ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity];
        if ($alsoReserved) {
            $params['reserved'] = $quantity;
        }
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Stock row is missing for the reservation change.');
        }
    }
}
