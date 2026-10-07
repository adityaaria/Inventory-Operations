<?php
declare(strict_types=1);
namespace Tests\Support;

use App\Repository\Contract\TransactionManagerInterface;

// Unit adapter only: real rollback behavior is verified against isolated MySQL.
final class ImmediateTransactions implements TransactionManagerInterface
{
    public function run(callable $operation): mixed { return $operation(); }
}
