<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\DraftCheckController;
use App\Entity\Product;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\DraftCheckService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DraftCheckServiceTest extends TestCase
{
    public function testReportsCurrentStatusAndStockForEveryDistinctProduct(): void
    {
        $result = $this->service()->check(new AuthContext(7, 'sales@test', 'Sales'), 'sales-order', 1, [10, 11, 10, 99]);

        self::assertSame(['id' => 1, 'active' => true], $result['warehouse']);
        self::assertSame([
            ['product_id' => 10, 'active' => true, 'available' => 4],
            ['product_id' => 11, 'active' => false, 'available' => 0],
            ['product_id' => 99, 'active' => false, 'available' => null],
        ], $result['items']);
    }

    public function testInactiveWarehouseHidesStockFigures(): void
    {
        $result = $this->service()->check(new AuthContext(1, 'admin@test', 'Admin'), 'stock-proposal', 2, [10]);

        self::assertSame(['id' => 2, 'active' => false], $result['warehouse']);
        self::assertNull($result['items'][0]['available']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbiddenForms(): array
    {
        return [
            'sales cannot submit purchase orders' => ['Sales', 'purchase-order'],
            'sales cannot submit stock proposals' => ['Sales', 'stock-proposal'],
            'warehouse cannot submit sales orders' => ['WarehouseStaff', 'sales-order'],
        ];
    }

    #[DataProvider('forbiddenForms')]
    public function testRolesOutsideTheCreateRouteAreForbidden(string $role, string $form): void
    {
        $this->expectException(HttpException::class);
        $this->service()->check(new AuthContext(7, 'user@test', $role), $form, 1, [10]);
    }

    public function testUnknownFormsAndOversizedChecksAreRejected(): void
    {
        $actor = new AuthContext(1, 'admin@test', 'Admin');
        try {
            $this->service()->check($actor, 'users', null, []);
            self::fail('Unknown form accepted.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $this->service()->check($actor, 'purchase-order', 1, range(1, 101));
    }

    public function testControllerReturnsJsonAndRejectsMalformedInput(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@test', 'Admin'));
        $controller = new DraftCheckController($this->service(), new AuthGuard($session));

        $ok = $controller->check(new Request('GET', '/drafts/check', ['form' => 'purchase-order', 'warehouse_id' => '1', 'product_ids' => ['10']], [], []));
        self::assertSame(200, $ok->statusCode());
        self::assertSame('no-store', $ok->headers()['Cache-Control']);
        self::assertSame(4, json_decode($ok->body(), true, 512, JSON_THROW_ON_ERROR)['items'][0]['available']);

        foreach ([['form' => ['x']], ['form' => 'purchase-order', 'product_ids' => '10'], ['form' => 'purchase-order', 'product_ids' => ['abc']], ['form' => 'purchase-order', 'warehouse_id' => '0']] as $query) {
            $response = $controller->check(new Request('GET', '/drafts/check', $query, [], []));
            self::assertSame(422, $response->statusCode());
            self::assertArrayHasKey('error', json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    private function service(): DraftCheckService
    {
        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-010', 'Active', 'pcs', 1.0, 2.0, 5, 1, true),
            new Product(11, 'SKU-011', 'Retired', 'pcs', 1.0, 2.0, 5, 1, false),
        ]);
        $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
        $warehouses->method('findById')->willReturnMap([
            [1, new Warehouse(1, 'Main', 'Jakarta', true)],
            [2, new Warehouse(2, 'Closed', 'Bandung', false)],
        ]);
        $stocks = new InMemoryStockRepository();
        $stocks->seed(10, 1, 4);

        return new DraftCheckService($products, $warehouses, $stocks);
    }
}
