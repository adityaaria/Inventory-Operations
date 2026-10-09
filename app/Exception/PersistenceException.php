<?php

declare(strict_types=1);

namespace App\Exception;

/** A database read, write or lock could not be completed. */
class PersistenceException extends \RuntimeException
{
}
