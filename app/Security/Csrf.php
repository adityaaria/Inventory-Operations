<?php

declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    public static function isValid(string $expected, string $actual): bool
    {
        return $expected !== '' && hash_equals($expected, $actual);
    }
}
