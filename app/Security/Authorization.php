<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

final class Authorization
{
    public static function canManageUsers(?AuthContext $authContext): bool
    {
        return $authContext?->role() === User::ROLE_ADMIN;
    }
}
