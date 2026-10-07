<?php
declare(strict_types=1);
namespace App\Repository\MySql;

use App\Repository\Contract\TransactionManagerInterface;
use PDO;
use Throwable;

final class MySqlTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(callable $operation): mixed
    {
        // Join the same connection-owned transaction; exceptions must propagate to its owner.
        if ($this->pdo->inTransaction()) { return $operation(); }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
