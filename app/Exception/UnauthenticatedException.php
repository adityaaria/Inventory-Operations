<?php

declare(strict_types=1);

namespace App\Exception;

final class UnauthenticatedException extends \RuntimeException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 401;
    }
}
