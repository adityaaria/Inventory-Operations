<?php
declare(strict_types=1);
namespace App\Service;
use App\Exception\ValidationException;
final class BusinessOperationInput
{
    public static function reason(mixed $value): string
    {
        if (!is_string($value) || trim($value)==='' || strlen($value)>500) { throw new ValidationException('A reason of at most 500 characters is required.'); }
        return trim($value);
    }
    public static function quantity(mixed $value,bool $zero=false): int
    {
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string)$value) || strlen((string)$value)>10 || (int)$value>4294967295 || (int)$value<($zero?0:1)) { throw new ValidationException('Invalid quantity.'); }
        return (int)$value;
    }
}
