<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

final class InputValidator
{
    public static function requiredString(string $field, mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new ValidationException(self::label($field) . ' is required.');
        }

        return $text;
    }

    public static function email(string $field, mixed $value): string
    {
        $email = trim((string) $value);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Valid ' . self::label($field) . ' is required.');
        }

        return $email;
    }

    /** @param list<string> $allowed */
    public static function enum(string $field, mixed $value, array $allowed): string
    {
        $text = (string) $value;
        if (!in_array($text, $allowed, true)) {
            throw new ValidationException('Invalid ' . self::label($field) . '.');
        }

        return $text;
    }

    public static function positiveInt(string $field, mixed $value): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($int) || $int <= 0) {
            throw new ValidationException(self::label($field) . ' must be positive.');
        }

        return $int;
    }

    public static function nonNegativeInt(string $field, mixed $value): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($int) || $int < 0) {
            throw new ValidationException(self::label($field) . ' cannot be negative.');
        }

        return $int;
    }

    public static function nonNegativeMoney(string $field, mixed $value): float
    {
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false || $float < 0) {
            throw new ValidationException(self::label($field) . ' cannot be negative.');
        }

        return (float) $float;
    }

    public static function date(string $field, mixed $value): string
    {
        $date = trim((string) $value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException('Invalid ' . self::label($field) . '.');
        }

        return $date;
    }

    private static function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }
}
