<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Exception\PersistenceException;
use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Support\OrderNumber;
use PDO;

final class MySqlDocumentSequenceRepository implements DocumentSequenceRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function currentPeriod(): string
    {
        // Same clock as `order_date = CURRENT_DATE` in the draft insert (and SET TIMESTAMP in the seed generator).
        $period = $this->pdo->query("SELECT DATE_FORMAT(CURRENT_DATE, '%y%m')");
        $value = $period === false ? false : $period->fetchColumn();
        if (!is_string($value)) {
            throw new PersistenceException('Unable to read the current order period.');
        }

        return $value;
    }

    public function next(string $prefix, string $period): int
    {
        OrderNumber::assertPrefix($prefix);
        OrderNumber::assertPeriod($period);
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Order number claims require a transaction.');
        }
        // One atomic statement: creates the row at 1 or increments it, keeping the row X-locked until the caller
        // commits. LAST_INSERT_ID(expr) hands the claimed value back on this connection only.
        $statement = $this->pdo->prepare(
            'INSERT INTO document_sequences (prefix, period, last_number) VALUES (:prefix, :period, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        );
        $statement->execute(['prefix' => $prefix, 'period' => $period]);
        $claimed = $this->pdo->query('SELECT LAST_INSERT_ID()');
        $value = $claimed === false ? 0 : (int) $claimed->fetchColumn();
        if ($value < 1) {
            throw new PersistenceException('Unable to claim an order number.');
        }

        return $value;
    }
}
