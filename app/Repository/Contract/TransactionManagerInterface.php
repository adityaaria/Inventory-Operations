<?php
declare(strict_types=1);
namespace App\Repository\Contract;

interface TransactionManagerInterface
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
