<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Security\AuthContext;

final class MasterDataAuthorizationService
{
    public function canWrite(AuthContext $actor): bool
    {
        return $actor->role() === User::ROLE_ADMIN;
    }

    public function canRead(AuthContext $actor): bool
    {
        return in_array($actor->role(), User::ROLES, true);
    }
}
