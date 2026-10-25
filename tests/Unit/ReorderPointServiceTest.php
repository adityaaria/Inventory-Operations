<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ProductController;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use App\Service\ReorderPointService;
use App\Support\ProductSearchCriteria;
use App\Support\ReorderThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImmediateTransactions;

final class ReorderPointServiceTest extends TestCase
{
    private InMemoryProductRepository $products;
    private InMemoryAuditLogRepository $audit;
    private ReorderPointService $service;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository([new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 10, 1, true)]);
        $this->products->setStockSnapshot(1, 1, 30);
        $this->products->setStockSnapshot(1, 2, 12);
        $this->audit = new InMemoryAuditLogRepository();
        $this->service = new ReorderPointService($this->products, new MasterDataAuthorizationService(), $this->audit, new ImmediateTransactions());
    }

    public function testAdminSetsOverrideAndAuditRecordsPreviousAndNewValue(): void
    {
        $stock = $this->service->setOverride($this->actor(User::ROLE_ADMIN), 1, 2, 15);

        self::assertSame(15, $stock->effectiveReorderPoint());
        self::assertTrue($stock->isLowStock(), '12 < 15 in warehouse 2 although 12 >= product default 10.');
        self::assertCount(1, $this->audit->entries());
        $entry = $this->audit->entries()[0];
        self::assertSame(ReorderPointService::AUDIT_ACTION, $entry['action']);
        self::assertSame('products', $entry['entity_type']);
        self::assertSame(1, $entry['entity_id']);
        self::assertSame(['warehouse_id' => 2, 'previous_override' => null, 'override' => 15, 'product_reorder_point' => 10, 'effective_reorder_point' => 15], $entry['metadata']);
    }

    public function testClearingOverrideFallsBackToProductDefaultAndUnchangedValueWritesNoAudit(): void
    {
        $admin = $this->actor(User::ROLE_ADMIN);
        $this->service->setOverride($admin, 1, 2, 15);
        $this->service->setOverride($admin, 1, 2, 15);
        self::assertCount(1, $this->audit->entries(), 'No-op save does not add an audit row.');

        $stock = $this->service->setOverride($admin, 1, 2, null);
        self::assertFalse($stock->hasReorderPointOverride());
        self::assertSame(10, $stock->effectiveReorderPoint());
        self::assertFalse($stock->isLowStock());
        self::assertSame(['warehouse_id' => 2, 'previous_override' => 15, 'override' => null, 'product_reorder_point' => 10, 'effective_reorder_point' => 10], $this->audit->entries()[1]['metadata']);
    }

    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'sales' => [User::ROLE_SALES];
        yield 'warehouse staff' => [User::ROLE_WAREHOUSE_STAFF];
    }

    #[DataProvider('nonAdminRoles')]
    public function testNonAdminRolesAreForbiddenAndNothingChanges(string $role): void
    {
        try {
            $this->service->setOverride($this->actor($role), 1, 2, 99);
            self::fail('Non-admin changed a reorder point.');
        } catch (HttpException $error) {
            self::assertSame(403, $error->statusCode());
        }
        self::assertNull($this->products->stocksForProduct(1)[1]->reorderPointOverride());
        self::assertSame([], $this->audit->entries());
    }

    public function testInvalidValueAndUnknownPairAreRejectedWithoutAudit(): void
    {
        $admin = $this->actor(User::ROLE_ADMIN);
        try {
            $this->service->setOverride($admin, 1, 2, ReorderThreshold::MAX + 1);
            self::fail('Out-of-range override accepted.');
        } catch (ValidationException) {
        }
        try {
            $this->service->setOverride($admin, 1, 9, 5);
            self::fail('Missing pair accepted.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->statusCode());
        }
        self::assertSame([], $this->audit->entries());
    }

    public function testProductFilterUsesAnyLowWarehouseInsteadOfTotal(): void
    {
        $this->products->create(new \App\Support\ProductInput('SKU-002', 'Widget B', 'pcs', 1.0, 2.0, 10, 1), true);
        $this->products->setStockSnapshot(2, 1, 50);
        $this->products->setStockSnapshot(2, 2, 50);
        // Product 1: 30 + 12 = 42 total, each warehouse >= 10, so normal. Override warehouse 2 to 15 => low there.
        $this->service->setOverride($this->actor(User::ROLE_ADMIN), 1, 2, 15);

        self::assertSame(['SKU-001'], $this->skus('low'));
        self::assertSame(['SKU-002'], $this->skus('normal'));
        $this->products->setWarehouseActive(2, false);
        self::assertSame([], $this->skus('low'), 'Inactive warehouses do not make a product low.');
    }

    public function testControllerSavesClearsAndReportsValidationOnDetailPage(): void
    {
        $controller = $this->controller(User::ROLE_ADMIN);
        $response = $controller->reorderPoint($this->post(['id' => '1', 'warehouse_id' => '2', 'reorder_point_override' => '15']));
        self::assertSame(302, $response->statusCode());
        self::assertSame('/products/show?id=1', $response->headers()['Location']);
        self::assertSame(15, $this->products->stocksForProduct(1)[1]->reorderPointOverride());

        $page = $controller->show(new Request('GET', '/products/show', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('Warehouse override', $page);
        self::assertStringContainsString('Product default', $page);
        self::assertStringContainsString('action="/products/reorder-point"', $page);
        self::assertStringContainsString('Low stock', $page);

        $invalid = $controller->reorderPoint($this->post(['id' => '1', 'warehouse_id' => '2', 'reorder_point_override' => '-4']));
        self::assertSame(422, $invalid->statusCode());
        self::assertStringContainsString('value="-4"', $invalid->body());
        self::assertStringContainsString('alert-danger', $invalid->body());
        self::assertSame(15, $this->products->stocksForProduct(1)[1]->reorderPointOverride());

        $controller->reorderPoint($this->post(['id' => '1', 'warehouse_id' => '2', 'reorder_point_override' => '']));
        self::assertNull($this->products->stocksForProduct(1)[1]->reorderPointOverride());
    }

    #[DataProvider('nonAdminRoles')]
    public function testNonAdminSeesReadOnlyThresholdsAndPostIsForbidden(string $role): void
    {
        $controller = $this->controller($role);
        $page = $controller->show(new Request('GET', '/products/show', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('Product default', $page);
        self::assertStringNotContainsString('action="/products/reorder-point"', $page);

        try {
            $controller->reorderPoint($this->post(['id' => '1', 'warehouse_id' => '2', 'reorder_point_override' => 'not-a-number']));
            self::fail('Non-admin POST accepted.');
        } catch (HttpException $error) {
            self::assertSame(403, $error->statusCode(), 'Authorization is checked before input parsing.');
        }
    }

    /** @return list<string> */
    private function skus(string $status): array
    {
        return array_map(static fn (Product $p): string => $p->sku(), $this->products->search(ProductSearchCriteria::fromArray(['stock_status' => $status]))->items());
    }

    private function actor(string $role): AuthContext
    {
        return new AuthContext(1, 'actor@example.test', $role);
    }

    /** @param array<string, string> $post */
    private function post(array $post): Request
    {
        return new Request('POST', '/products/reorder-point', [], $post, []);
    }

    private function controller(string $role): ProductController
    {
        $session = new SessionManager();
        $session->login($this->actor($role));

        return new ProductController(
            new ProductService($this->products, new MasterDataAuthorizationService()),
            $this->products,
            new InMemoryCategoryRepository([new Category(1, 'Finished Goods', 'Ready-to-sell inventory', true)]),
            new AuthGuard($session),
            null,
            $this->service,
        );
    }
}
