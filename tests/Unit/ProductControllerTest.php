<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ProductController;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use PHPUnit\Framework\TestCase;

final class ProductControllerTest extends TestCase
{
    public function testProductListRendersCatalogRows(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(2, 'sales@example.test', User::ROLE_SALES));
        $controller = $this->controller($session);

        $response = $controller->index(new Request('GET', '/products', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('SKU-001', $response->body());
    }

    public function testCreateFormRequiresAdmin(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(2, 'sales@example.test', User::ROLE_SALES));
        $controller = $this->controller($session);

        $this->expectException(HttpException::class);

        $controller->create(new Request('GET', '/products/create', [], [], []));
    }

    public function testAdminCreateFormIncludesPurchaseAndSellingPriceFields(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $controller = $this->controller($session);

        $response = $controller->create(new Request('GET', '/products/create', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('name="purchase_price"', $response->body());
        self::assertStringContainsString('name="selling_price"', $response->body());
    }

    public function testAdminCanImportProductCsv(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $products = new InMemoryProductRepository();
        $controller = new ProductController(
            new ProductService($products, new MasterDataAuthorizationService()),
            $products,
            new InMemoryCategoryRepository([new Category(1, 'Finished Goods', 'Ready-to-sell inventory', true)]),
            new AuthGuard($session),
        );

        $response = $controller->import(new Request('POST', '/products/import', [], [
            'csv_data' => "sku,name,unit,purchase_price,selling_price,reorder_point,category_id\nSKU-IMPORT,Imported Product,pcs,1000,1500,5,1\n",
        ], []));

        self::assertSame(302, $response->statusCode());
        self::assertSame('SKU-IMPORT', $products->search(\App\Support\ProductSearchCriteria::fromArray(['q' => 'SKU-IMPORT']))->items()[0]->sku());
    }

    private function controller(SessionManager $session): ProductController
    {
        $products = new InMemoryProductRepository([
            new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);
        $products->setStockSnapshot(1, 1, 3);

        return new ProductController(
            new ProductService($products, new MasterDataAuthorizationService()),
            $products,
            new InMemoryCategoryRepository([new Category(1, 'Finished Goods', 'Ready-to-sell inventory', true)]),
            new AuthGuard($session),
        );
    }
}
