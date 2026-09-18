<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Security\AuthContext;

final class DashboardService
{
    public function __construct(private readonly OperationalQueryRepositoryInterface $queries)
    {
    }

    /** @return array<string, mixed> */
    public function forActor(AuthContext $actor): array
    {
        if ($actor->role() === User::ROLE_SALES) {
            return ['role' => User::ROLE_SALES] + $this->queries->salesDashboard($actor->userId());
        }
        if ($actor->role() === User::ROLE_WAREHOUSE_STAFF) {
            return ['role' => User::ROLE_WAREHOUSE_STAFF] + $this->queries->warehouseDashboard();
        }

        return ['role' => User::ROLE_ADMIN] + $this->queries->adminDashboard();
    }
}
