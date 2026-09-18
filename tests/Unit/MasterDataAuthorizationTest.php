<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Security\AuthContext;
use App\Service\MasterDataAuthorizationService;
use PHPUnit\Framework\TestCase;

final class MasterDataAuthorizationTest extends TestCase
{
    public function testAdminCanWriteMasterData(): void
    {
        $auth = new MasterDataAuthorizationService();

        self::assertTrue($auth->canWrite(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN)));
    }

    public function testSalesAndWarehouseStaffCannotWriteMasterData(): void
    {
        $auth = new MasterDataAuthorizationService();

        self::assertFalse($auth->canWrite(new AuthContext(2, 'sales@example.test', User::ROLE_SALES)));
        self::assertFalse($auth->canWrite(new AuthContext(3, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF)));
    }
}
