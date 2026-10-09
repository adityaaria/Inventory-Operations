<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\HomeController;
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
use App\Support\ProductSearchCriteria;
use PHPUnit\Framework\TestCase;

final class ProductControllerCoverageTest extends TestCase
{
    private InMemoryProductRepository $products;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository([
            new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);
    }

    public function testSearchWithNoMatchesRendersEmptyStateRow(): void
    {
        $response = $this->controller(User::ROLE_SALES)->index(new Request('GET', '/products', ['q' => 'no-such-product'], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('No products found.', $response->body());
        self::assertStringContainsString('colspan="8"', $response->body());
        self::assertStringNotContainsString('SKU-001', $response->body());
    }

    public function testAdminEmptySearchSpansActionColumn(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->index(new Request('GET', '/products', ['q' => 'zzz'], [], []));

        self::assertStringContainsString('colspan="9"', $response->body());
        self::assertSame([], $this->products->search(ProductSearchCriteria::fromArray(['q' => 'zzz']))->items());
    }

    public function testStoreCreatesProductAndRedirects(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->store($this->post([
            'sku' => 'SKU-NEW',
            'name' => 'Gadget',
            'unit' => 'box',
            'purchase_price' => '200',
            'selling_price' => '350.5',
            'reorder_point' => '2',
            'category_id' => '1',
        ]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/products', $response->headers()['Location']);
        $created = $this->products->findById(2);
        self::assertSame('SKU-NEW', $created?->sku());
        self::assertSame(350.5, $created?->sellingPrice());
        self::assertTrue($created?->isActive());
    }

    public function testEditRendersExistingProduct(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->edit(new Request('GET', '/products/edit', ['id' => '1'], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('value="SKU-001"', $response->body());
        self::assertStringContainsString('value="Widget A"', $response->body());
        self::assertStringContainsString('Finished Goods', $response->body());
    }

    public function testEditUnknownProductIs404AndNonAdminIs403(): void
    {
        try {
            $this->controller(User::ROLE_ADMIN)->edit(new Request('GET', '/products/edit', ['id' => '42'], [], []));
            self::fail('Expected 404.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode());
            self::assertSame('Product not found.', $exception->getMessage());
        }

        try {
            $this->controller(User::ROLE_SALES)->edit(new Request('GET', '/products/edit', ['id' => '1'], [], []));
            self::fail('Expected 403.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
    }

    public function testUpdateChangesProductAndRedirects(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->update($this->post([
            'id' => '1',
            'sku' => 'SKU-001',
            'name' => 'Widget A Plus',
            'unit' => 'pcs',
            'purchase_price' => '1100',
            'selling_price' => '1700',
            'reorder_point' => '7',
            'category_id' => '1',
        ]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/products', $response->headers()['Location']);
        $product = $this->products->findById(1);
        self::assertSame('Widget A Plus', $product?->name());
        self::assertSame(7, $product?->reorderPoint());
        self::assertSame(1700.0, $product?->sellingPrice());
    }

    public function testDeactivateAndActivateToggleProduct(): void
    {
        $controller = $this->controller(User::ROLE_ADMIN);

        $response = $controller->deactivate($this->post(['id' => '1']));
        self::assertSame(302, $response->statusCode());
        self::assertSame('/products', $response->headers()['Location']);
        self::assertFalse($this->products->findById(1)?->isActive());
        self::assertSame([], $this->products->active());

        $response = $controller->activate($this->post(['id' => '1']));
        self::assertSame(302, $response->statusCode());
        self::assertTrue($this->products->findById(1)?->isActive());
    }

    public function testNonAdminCannotToggleProduct(): void
    {
        $controller = $this->controller(User::ROLE_WAREHOUSE_STAFF);

        foreach (['activate', 'deactivate'] as $action) {
            try {
                $controller->{$action}($this->post(['id' => '1']));
                self::fail('Expected 403.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
        }

        self::assertTrue($this->products->findById(1)?->isActive());
    }

    public function testHomePageRendersPrimaryNavigation(): void
    {
        $response = (new HomeController())->index();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('<h1>Inventory & Order Management</h1>', $response->body());
        foreach (['/dashboard', '/products', '/purchase-orders', '/sales-orders', '/users'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $response->body());
        }
    }

    /** @param array<string, string> $post */
    private function post(array $post): Request
    {
        return new Request('POST', '/products', [], $post, []);
    }

    private function controller(string $role): ProductController
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'actor@example.test', $role));

        return new ProductController(
            new ProductService($this->products, new MasterDataAuthorizationService()),
            $this->products,
            new InMemoryCategoryRepository([new Category(1, 'Finished Goods', 'Ready-to-sell inventory', true)]),
            new AuthGuard($session),
        );
    }
}
