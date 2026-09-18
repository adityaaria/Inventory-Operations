<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 422;
    }
}
