<?php
declare(strict_types=1);
namespace App\Repository\MySql;

use App\Exception\ValidationException;
use PDOException;

final class PersistenceErrors
{
    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function write(callable $write): mixed
    {
        try {
            return $write();
        } catch (PDOException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);
            $message = match ($code) {
                1062 => 'A record with that unique value already exists.',
                1451, 1452 => 'The selected related record is invalid or in use.',
                1406, 1264 => 'A value exceeds the allowed length or numeric range.',
                3819 => 'A value violates a required data constraint.',
                default => null,
            };
            if ($message !== null) throw new ValidationException($message);
            throw $exception;
        }
    }
}
