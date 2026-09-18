<?php

declare(strict_types=1);

namespace App\Support;

final class Html
{
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
