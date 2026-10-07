<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ProductController;
use App\Entity\{Product, Category};
use App\Http\Request;
use App\Repository\InMemory\{InMemoryProductRepository, InMemoryCategoryRepository};
use App\Security\{AuthContext, AuthGuard, SessionManager};
use App\Service\{ProductService, MasterDataAuthorizationService};
use App\Support\{FormState, ProductSearchCriteria};
use PHPUnit\Framework\TestCase;

final class FormStateTest extends TestCase
{
    public function testAttemptedValuesOverrideDefaultsIncludingEmptyAndZero(): void
    {
        $state = new FormState(['name'=>'', 'quantity'=>'0', 'category_id'=>'2', 'bad'=>['nested']]);
        self::assertSame('', $state->value('name', 'Old name'));
        self::assertSame('0', $state->value('quantity', 10));
        self::assertSame('', $state->value('bad', 'Fallback'));
        self::assertSame('selected', $state->selected('category_id', 2));
        self::assertSame('', $state->selected('category_id', 1, true));
        self::assertSame('selected', $state->selected('other', 1, true));
    }

    public function testPasswordsAndCsrfCannotBeRestoredFromAttemptedDataOrFallback(): void
    {
        $state = new FormState(['password'=>'secret', 'csrf_token'=>'old-token']);
        self::assertSame('', $state->value('password', 'fallback secret'));
        self::assertSame('', $state->value('csrf_token'));
    }

    public function testActiveCatalogIsNotPaginatedAndOmitsInactiveProducts(): void
    {
        $products = [];
        for ($id = 1; $id <= 25; $id++) $products[] = new Product($id, 'SKU-'.$id, 'Product '.$id, 'pcs', 1.0, 2.0, 1, 1, $id !== 25);
        $repository = new InMemoryProductRepository($products);
        self::assertCount(24, $repository->active());
        self::assertCount(10, $repository->search(ProductSearchCriteria::fromArray([]))->items());
        self::assertNotContains(25, array_map(static fn (Product $product): int => $product->id(), $repository->active()));
    }

    public function testInvalidProductRendersEscapedAttemptedValuesWithoutPersisting(): void
    {
        $repository = new InMemoryProductRepository();
        $controller = $this->controller($repository);
        $response = $controller->store(new Request('POST','/products',[],['sku'=>'E2E-KEEP','name'=>'"<script>','unit'=>'pcs','purchase_price'=>'12.50','selling_price'=>'20','reorder_point'=>'-1','category_id'=>'2'],[]));
        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('value="&quot;&lt;script&gt;"', $response->body());
        self::assertStringContainsString('value="12.50"', $response->body());
        self::assertStringContainsString('value="-1"', $response->body());
        self::assertStringContainsString('value="2" selected', $response->body());
        self::assertCount(0, $repository->search(ProductSearchCriteria::fromArray([]))->items());
    }

    public function testInvalidEditRetainsAttemptedInputWithoutChangingEntity(): void
    {
        $repository = new InMemoryProductRepository([new Product(1,'OLD','Old name','pcs',1.0,2.0,1,1,true)]);
        $controller = $this->controller($repository);
        $response = $controller->update(new Request('POST','/products/update',[],['id'=>'1','sku'=>'NEW','name'=>'New name','unit'=>'kg','purchase_price'=>'5','selling_price'=>'6','reorder_point'=>'-1','category_id'=>'2'],[]));
        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('value="New name"', $response->body());
        self::assertStringContainsString('value="2" selected', $response->body());
        self::assertSame('Old name', $repository->findById(1)->name());
    }

    private function controller(InMemoryProductRepository $repository): ProductController
    {
        $session = new SessionManager(); $session->login(new AuthContext(1,'admin@test','Admin'));
        return new ProductController(new ProductService($repository,new MasterDataAuthorizationService()),$repository,new InMemoryCategoryRepository([new Category(1,'One','',true),new Category(2,'Two','',true)]),new AuthGuard($session));
    }
}
