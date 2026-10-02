<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\DashboardController;
use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\DashboardService;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    public function testAdminGetsGlobalDashboard(): void
    {
        $dashboard = (new DashboardService(new InMemoryOperationalQueryRepository()))->forActor(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));

        self::assertSame('Admin', $dashboard['role']);
        self::assertArrayHasKey('inventory_value', $dashboard);
        self::assertArrayHasKey('sales_orders_by_status', $dashboard);
    }

    public function testSalesDashboardIsScopedToOwnOrders(): void
    {
        $dashboard = (new DashboardService(new InMemoryOperationalQueryRepository()))->forActor(new AuthContext(7, 'sales@example.test', User::ROLE_SALES));

        self::assertSame(7, $dashboard['sales_user_id']);
        self::assertArrayNotHasKey('inventory_value', $dashboard);
    }

    public function testWarehouseStaffGetsWarehouseDashboard(): void
    {
        $dashboard = (new DashboardService(new InMemoryOperationalQueryRepository()))->forActor(new AuthContext(9, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF));

        self::assertSame(User::ROLE_WAREHOUSE_STAFF, $dashboard['role']);
        self::assertArrayNotHasKey('inventory_value', $dashboard);
        self::assertArrayNotHasKey('sales_user_id', $dashboard);
    }

    public function testDashboardControllerRequiresLogin(): void
    {
        $controller = new DashboardController(
            new DashboardService(new InMemoryOperationalQueryRepository()),
            new AuthGuard(new SessionManager()),
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Authentication required.');

        $controller->index(new Request('GET', '/', [], [], []));
    }

    public function testDashboardControllerRendersAsDefaultAuthenticatedPage(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $controller = new DashboardController(
            new DashboardService(new InMemoryOperationalQueryRepository()),
            new AuthGuard($session),
        );

        $response = $controller->index(new Request('GET', '/', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Dashboard', $response->body());
    }
}
