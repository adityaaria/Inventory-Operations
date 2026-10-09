<?php

declare(strict_types=1);

namespace App\Exception;

/** Session, file, log or runtime configuration failure outside the database. */
final class InfrastructureException extends \RuntimeException
{
}
