<?php

declare(strict_types=1);

namespace App\Exception;

/** A record that must exist (by id or after a write) was not found. */
final class EntityNotFoundException extends PersistenceException
{
}
