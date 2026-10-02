<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Product;
use App\Entity\User;
use App\Exception\HttpException;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Security\AuthContext;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    public function testAdminCanCreateProduct(): void
    {
        $service = new ProductService(new InMemoryProductRepository(), new MasterDataAuthorizationService());

        $product = $service->create(
            new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN),
            new ProductInput('SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1),
        );

        self::assertSame('SKU-001', $product->sku());
        self::assertSame(1000.0, $product->purchasePrice());
        self::assertSame(1500.0, $product->sellingPrice());
    }

    public function testNonAdminCannotCreateProduct(): void
    {
        $service = new ProductService(new InMemoryProductRepository(), new MasterDataAuthorizationService());

        $this->expectException(HttpException::class);

        $service->create(
            new AuthContext(2, 'sales@example.test', User::ROLE_SALES),
            new ProductInput('SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1),
        );
    }

    public function testRejectsInvalidProductInput(): void
    {
        $service = new ProductService(new InMemoryProductRepository(), new MasterDataAuthorizationService());

        $this->expectException(InvalidArgumentException::class);

        $service->create(
            new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN),
            new ProductInput('', '', '', -1.0, -1.0, -1, 1),
        );
    }

    public function testAnyRoleCanSearchProducts(): void
    {
        $service = new ProductService(
            new InMemoryProductRepository([new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]),
            new MasterDataAuthorizationService(),
        );

        $result = $service->search(new AuthContext(2, 'sales@example.test', User::ROLE_SALES), ProductSearchCriteria::fromArray([]));

        self::assertSame(1, $result->total());
    }

    public function testAdminCanUpdateProduct(): void
    {
        $repository = new InMemoryProductRepository([new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]);
        $service = new ProductService($repository, new MasterDataAuthorizationService());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $updated = $service->update($admin, 1, new ProductInput('SKU-001', 'Widget A+', 'pcs', 1100.0, 1600.0, 6, 1));

        self::assertSame('Widget A+', $updated->name());
        self::assertSame(1100.0, $updated->purchasePrice());
    }

    public function testAdminCanDeactivateProduct(): void
    {
        $repository = new InMemoryProductRepository([new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]);
        $service = new ProductService($repository, new MasterDataAuthorizationService());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $service->setActive($admin, 1, false);

        self::assertFalse($repository->findById(1)?->isActive());
    }

    public function testNonAdminCannotUpdateProduct(): void
    {
        $repository = new InMemoryProductRepository([new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]);
        $service = new ProductService($repository, new MasterDataAuthorizationService());

        $this->expectException(HttpException::class);

        $service->update(new AuthContext(2, 'sales@example.test', User::ROLE_SALES), 1, new ProductInput('SKU-001', 'Widget A+', 'pcs', 1100.0, 1600.0, 6, 1));
    }

    /** @dataProvider invalidProductInputProvider */
    public function testCreateRejectsEachInvalidFieldIndependently(ProductInput $input): void
    {
        $service = new ProductService(new InMemoryProductRepository(), new MasterDataAuthorizationService());

        $this->expectException(InvalidArgumentException::class);

        $service->create(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), $input);
    }

    /** @return list<array{0: ProductInput}> */
    public static function invalidProductInputProvider(): array
    {
        return [
            'blank name' => [new ProductInput('SKU-001', '', 'pcs', 1000.0, 1500.0, 5, 1)],
            'blank unit' => [new ProductInput('SKU-001', 'Widget A', '', 1000.0, 1500.0, 5, 1)],
            'negative purchase price' => [new ProductInput('SKU-001', 'Widget A', 'pcs', -1.0, 1500.0, 5, 1)],
            'negative selling price' => [new ProductInput('SKU-001', 'Widget A', 'pcs', 1000.0, -1.0, 5, 1)],
            'negative reorder point' => [new ProductInput('SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, -1, 1)],
            'missing category' => [new ProductInput('SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 0)],
        ];
    }
}
