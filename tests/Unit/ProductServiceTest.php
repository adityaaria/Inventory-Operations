<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Exception\HttpException;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Security\AuthContext;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    public function testAdminCanCreateProduct(): void
    {
        $service = new ProductService(new InMemoryProductRepository(), new MasterDataAuthorizationService());

        $product = $service->create(
            new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN),
            'SKU-001',
            'Widget A',
            'pcs',
            1000.0,
            1500.0,
            5,
            1,
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
            'SKU-001',
            'Widget A',
            'pcs',
            1000.0,
            1500.0,
            5,
            1,
        );
    }

    public function testRejectsInvalidProductInput(): void
    {
        $service = new ProductService(new InMemoryProductRepository(), new MasterDataAuthorizationService());

        $this->expectException(InvalidArgumentException::class);

        $service->create(
            new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN),
            '',
            '',
            '',
            -1.0,
            -1.0,
            -1,
            1,
        );
    }

}
