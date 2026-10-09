<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\CategoryController;
use App\Controller\CustomerController;
use App\Controller\SupplierController;
use App\Controller\WarehouseController;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\EntityNotFoundException;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\SupplierRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\CategoryService;
use App\Service\CustomerService;
use App\Service\MasterDataAuthorizationService;
use App\Service\SupplierService;
use App\Service\WarehouseService;
use PHPUnit\Framework\TestCase;

final class MasterDataControllerCoverageTest extends TestCase
{
    // ---------------------------------------------------------------- Category

    public function testCategoryIndexIsReadableBySalesWithoutWriteActions(): void
    {
        $repository = $this->categoryRepository();
        $response = $this->categoryController($repository, User::ROLE_SALES)->index(new Request('GET', '/categories', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Finished Goods', $response->body());
        self::assertStringNotContainsString('href="/categories/create"', $response->body());
    }

    public function testCategoryCreateFormRequiresAdmin(): void
    {
        $repository = $this->categoryRepository();
        $response = $this->categoryController($repository, User::ROLE_ADMIN)->create();
        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('name="description"', $response->body());

        $this->assertForbidden(fn () => $this->categoryController($repository, User::ROLE_SALES)->create());
    }

    public function testCategoryStoreCreatesActiveCategory(): void
    {
        $repository = $this->categoryRepository();
        $response = $this->categoryController($repository, User::ROLE_ADMIN)->store($this->post(['name' => '  Spare Parts ', 'description' => ' Replacement items ']));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/categories', $response->headers()['Location']);
        $created = $repository->findById(2);
        self::assertSame('Spare Parts', $created?->name());
        self::assertSame('Replacement items', $created?->description());
        self::assertTrue($created?->isActive());
    }

    public function testCategoryEditUpdateAndToggleLifecycle(): void
    {
        $repository = $this->categoryRepository();
        $controller = $this->categoryController($repository, User::ROLE_ADMIN);

        $edit = $controller->edit(new Request('GET', '/categories/edit', ['id' => '1'], [], []));
        self::assertSame(200, $edit->statusCode());
        self::assertStringContainsString('value="Finished Goods"', $edit->body());
        self::assertStringContainsString('Ready-to-sell inventory</textarea>', $edit->body());

        $update = $controller->update($this->post(['id' => '1', 'name' => 'Goods', 'description' => 'Updated']));
        self::assertSame(302, $update->statusCode());
        self::assertSame('Goods', $repository->findById(1)?->name());
        self::assertSame('Updated', $repository->findById(1)?->description());

        self::assertSame(302, $controller->deactivate($this->post(['id' => '1']))->statusCode());
        self::assertFalse($repository->findById(1)?->isActive());
        self::assertSame([], $repository->active());

        $activate = $controller->activate($this->post(['id' => '1']));
        self::assertSame('/categories', $activate->headers()['Location']);
        self::assertTrue($repository->findById(1)?->isActive());
        self::assertSame('Goods', $repository->findById(1)?->name());
    }

    public function testCategoryEditUnknownIdIs404(): void
    {
        $this->assertHttpStatus(404, fn () => $this->categoryController($this->categoryRepository(), User::ROLE_ADMIN)
            ->edit(new Request('GET', '/categories/edit', ['id' => '99'], [], [])));
    }

    public function testCategoryUpdateValidationFailureKeepsOldInput(): void
    {
        $repository = $this->categoryRepository();
        $response = $this->categoryController($repository, User::ROLE_ADMIN)->update($this->post(['id' => '1', 'name' => '   ', 'description' => '"<i>kept']));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsStringIgnoringCase('name is required.', $response->body());
        self::assertStringContainsString('&quot;&lt;i&gt;kept', $response->body());
        self::assertSame('Finished Goods', $repository->findById(1)?->name());
    }

    public function testCategoryUpdateOfUnknownIdFailsInRepository(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->categoryController($this->categoryRepository(), User::ROLE_ADMIN)->update($this->post(['id' => '99', 'name' => 'Ghost', 'description' => '']));
    }

    public function testCategorySetActiveOnUnknownIdFails(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->categoryController($this->categoryRepository(), User::ROLE_ADMIN)->deactivate($this->post(['id' => '99']));
    }

    public function testCategoryWritesAreForbiddenForNonAdmin(): void
    {
        $repository = $this->categoryRepository();
        $controller = $this->categoryController($repository, User::ROLE_WAREHOUSE_STAFF);

        $this->assertForbidden(fn () => $controller->edit(new Request('GET', '/categories/edit', ['id' => '1'], [], [])));
        $this->assertForbidden(fn () => $controller->update($this->post(['id' => '1', 'name' => 'Hacked', 'description' => ''])));
        $this->assertForbidden(fn () => $controller->deactivate($this->post(['id' => '1'])));
        $this->assertForbidden(fn () => $controller->activate($this->post(['id' => '1'])));
        self::assertSame('Finished Goods', $repository->findById(1)?->name());
        self::assertTrue($repository->findById(1)?->isActive());
    }

    public function testCategoryServiceListsAllAndEnforcesWriteAuthorization(): void
    {
        $repository = $this->categoryRepository();
        $service = new CategoryService($repository, new MasterDataAuthorizationService());
        $sales = new AuthContext(2, 'sales@example.test', User::ROLE_SALES);

        self::assertCount(1, $service->all($sales));
        $this->assertForbidden(fn () => $service->create($sales, 'X', ''));
        $this->assertForbidden(fn () => $service->update($sales, 1, 'X', ''));
        $this->assertForbidden(fn () => $service->setActive($sales, 1, false));
        self::assertCount(1, $repository->all());
        self::assertTrue($repository->findById(1)?->isActive());
    }

    // ---------------------------------------------------------------- Warehouse

    public function testWarehouseIndexCreateAndStore(): void
    {
        $repository = $this->createMock(WarehouseRepositoryInterface::class);
        $repository->method('paginate')->willReturn(new \App\Support\PaginatedResult([new Warehouse(1, 'Main Hub', 'Jakarta', true)], 1, 1, 20));
        $repository->expects(self::once())->method('create')->with('North', 'Medan', true)->willReturn(new Warehouse(2, 'North', 'Medan', true));

        $index = $this->warehouseController($repository, User::ROLE_SALES)->index(new Request('GET', '/warehouses', [], [], []));
        self::assertSame(200, $index->statusCode());
        self::assertStringContainsString('Main Hub', $index->body());

        $controller = $this->warehouseController($repository, User::ROLE_ADMIN);
        $create = $controller->create();
        self::assertSame(200, $create->statusCode());
        self::assertStringContainsString('name="location"', $create->body());

        $store = $controller->store($this->post(['name' => ' North ', 'location' => ' Medan ']));
        self::assertSame(302, $store->statusCode());
        self::assertSame('/warehouses', $store->headers()['Location']);
    }

    public function testWarehouseEditUpdateAndToggle(): void
    {
        $repository = $this->createMock(WarehouseRepositoryInterface::class);
        $repository->method('findById')->willReturnMap([[1, new Warehouse(1, 'Main Hub', 'Jakarta', true)], [99, null]]);
        $repository->expects(self::once())->method('update')->with(1, 'Main', 'Bandung')->willReturn(new Warehouse(1, 'Main', 'Bandung', true));
        $toggles = [];
        $repository->method('setActive')->willReturnCallback(function (int $id, bool $active) use (&$toggles): void {
            $toggles[] = [$id, $active];
        });
        $controller = $this->warehouseController($repository, User::ROLE_ADMIN);

        $edit = $controller->edit(new Request('GET', '/warehouses/edit', ['id' => '1'], [], []));
        self::assertSame(200, $edit->statusCode());
        self::assertStringContainsString('value="Main Hub"', $edit->body());
        self::assertStringContainsString('value="Jakarta"', $edit->body());

        $this->assertHttpStatus(404, fn () => $controller->edit(new Request('GET', '/warehouses/edit', ['id' => '99'], [], [])));

        self::assertSame(302, $controller->update($this->post(['id' => '1', 'name' => 'Main', 'location' => 'Bandung']))->statusCode());
        self::assertSame('/warehouses', $controller->deactivate($this->post(['id' => '1']))->headers()['Location']);
        self::assertSame(302, $controller->activate($this->post(['id' => '1']))->statusCode());
        self::assertSame([[1, false], [1, true]], $toggles);
    }

    public function testWarehouseUpdateValidationFailureRerendersForm(): void
    {
        $repository = $this->createMock(WarehouseRepositoryInterface::class);
        $repository->method('findById')->willReturn(new Warehouse(1, 'Main Hub', 'Jakarta', true));
        $repository->expects(self::never())->method('update');

        $response = $this->warehouseController($repository, User::ROLE_ADMIN)->update($this->post(['id' => '1', 'name' => '', 'location' => '"<x>loc']));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsStringIgnoringCase('name is required.', $response->body());
        self::assertStringContainsString('&quot;&lt;x&gt;loc', $response->body());
    }

    public function testWarehouseServiceListsAllAndEnforcesWriteAuthorization(): void
    {
        $repository = $this->createMock(WarehouseRepositoryInterface::class);
        $repository->method('all')->willReturn([new Warehouse(1, 'Main Hub', 'Jakarta', true)]);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('setActive');
        $service = new WarehouseService($repository, new MasterDataAuthorizationService());
        $staff = new AuthContext(3, 'wh@example.test', User::ROLE_WAREHOUSE_STAFF);

        self::assertSame('Main Hub', $service->all($staff)[0]->name());
        $this->assertForbidden(fn () => $service->update($staff, 1, 'X', ''));
        $this->assertForbidden(fn () => $service->setActive($staff, 1, false));
    }

    // ---------------------------------------------------------------- Supplier

    public function testSupplierLifecycleThroughController(): void
    {
        $repository = $this->createMock(SupplierRepositoryInterface::class);
        $repository->method('paginate')->willReturn(new \App\Support\PaginatedResult([new Supplier(1, 'Acme Supply', 'acme@example.test', '0811', 'Jl. A', true)], 1, 1, 20));
        $repository->method('findById')->willReturnMap([[1, new Supplier(1, 'Acme Supply', 'acme@example.test', '0811', 'Jl. A', true)], [99, null]]);
        $repository->expects(self::once())->method('create')->with('Beta', 'beta@example.test', '0822', 'Jl. B', true)
            ->willReturn(new Supplier(2, 'Beta', 'beta@example.test', '0822', 'Jl. B', true));
        $repository->expects(self::once())->method('update')->with(1, 'Acme', 'sales@acme.test', '0899', 'Jl. C')
            ->willReturn(new Supplier(1, 'Acme', 'sales@acme.test', '0899', 'Jl. C', true));
        $toggles = [];
        $repository->method('setActive')->willReturnCallback(function (int $id, bool $active) use (&$toggles): void {
            $toggles[] = [$id, $active];
        });

        $index = $this->supplierController($repository, User::ROLE_WAREHOUSE_STAFF)->index(new Request('GET', '/suppliers', [], [], []));
        self::assertSame(200, $index->statusCode());
        self::assertStringContainsString('Acme Supply', $index->body());

        $controller = $this->supplierController($repository, User::ROLE_ADMIN);
        $create = $controller->create();
        self::assertSame(200, $create->statusCode());
        self::assertStringContainsString('name="phone"', $create->body());

        self::assertSame(302, $controller->store($this->post(['name' => 'Beta', 'email' => 'beta@example.test', 'phone' => '0822', 'address' => 'Jl. B']))->statusCode());

        $edit = $controller->edit(new Request('GET', '/suppliers/edit', ['id' => '1'], [], []));
        self::assertSame(200, $edit->statusCode());
        self::assertStringContainsString('value="acme@example.test"', $edit->body());
        self::assertStringContainsString('Jl. A</textarea>', $edit->body());
        $this->assertHttpStatus(404, fn () => $controller->edit(new Request('GET', '/suppliers/edit', ['id' => '99'], [], [])));

        $update = $controller->update($this->post(['id' => '1', 'name' => 'Acme', 'email' => 'sales@acme.test', 'phone' => '0899', 'address' => 'Jl. C']));
        self::assertSame('/suppliers', $update->headers()['Location']);

        self::assertSame(302, $controller->deactivate($this->post(['id' => '1']))->statusCode());
        self::assertSame(302, $controller->activate($this->post(['id' => '1']))->statusCode());
        self::assertSame([[1, false], [1, true]], $toggles);
    }

    public function testSupplierUpdateWithInvalidEmailRerendersForm(): void
    {
        $repository = $this->createMock(SupplierRepositoryInterface::class);
        $repository->method('findById')->willReturn(new Supplier(1, 'Acme Supply', 'acme@example.test', '0811', 'Jl. A', true));
        $repository->expects(self::never())->method('update');

        $response = $this->supplierController($repository, User::ROLE_ADMIN)->update($this->post(['id' => '1', 'name' => 'Acme', 'email' => 'broken', 'phone' => '', 'address' => '']));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Valid email is required.', $response->body());
        self::assertStringContainsString('value="broken"', $response->body());
    }

    public function testSupplierWritesAndServiceAreAuthorized(): void
    {
        $repository = $this->createMock(SupplierRepositoryInterface::class);
        $repository->method('all')->willReturn([new Supplier(1, 'Acme Supply', '', '', '', true)]);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('setActive');
        $controller = $this->supplierController($repository, User::ROLE_SALES);

        $this->assertForbidden(fn () => $controller->create());
        $this->assertForbidden(fn () => $controller->edit(new Request('GET', '/suppliers/edit', ['id' => '1'], [], [])));
        $this->assertForbidden(fn () => $controller->update($this->post(['id' => '1', 'name' => 'X', 'email' => '', 'phone' => '', 'address' => ''])));
        $this->assertForbidden(fn () => $controller->deactivate($this->post(['id' => '1'])));

        $service = new SupplierService($repository, new MasterDataAuthorizationService());
        $sales = new AuthContext(2, 'sales@example.test', User::ROLE_SALES);
        self::assertSame('Acme Supply', $service->all($sales)[0]->name());
        $this->assertForbidden(fn () => $service->update($sales, 1, 'X', '', '', ''));
        $this->assertForbidden(fn () => $service->setActive($sales, 1, true));
    }

    // ---------------------------------------------------------------- Customer

    public function testCustomerLifecycleThroughController(): void
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('paginate')->willReturn(new \App\Support\PaginatedResult([new Customer(1, 'Toko Maju', 'maju@example.test', '0811', 'Jl. A', true)], 1, 1, 20));
        $repository->method('findById')->willReturnMap([[1, new Customer(1, 'Toko Maju', 'maju@example.test', '0811', 'Jl. A', true)], [99, null]]);
        $repository->expects(self::once())->method('create')->with('Toko Baru', '', '', '', true)
            ->willReturn(new Customer(2, 'Toko Baru', '', '', '', true));
        $repository->expects(self::once())->method('update')->with(1, 'Toko Maju Jaya', 'jaya@example.test', '0855', 'Jl. D')
            ->willReturn(new Customer(1, 'Toko Maju Jaya', 'jaya@example.test', '0855', 'Jl. D', true));
        $toggles = [];
        $repository->method('setActive')->willReturnCallback(function (int $id, bool $active) use (&$toggles): void {
            $toggles[] = [$id, $active];
        });

        $index = $this->customerController($repository, User::ROLE_SALES)->index(new Request('GET', '/customers', [], [], []));
        self::assertSame(200, $index->statusCode());
        self::assertStringContainsString('Toko Maju', $index->body());

        $controller = $this->customerController($repository, User::ROLE_ADMIN);
        $create = $controller->create();
        self::assertSame(200, $create->statusCode());
        self::assertStringContainsString('name="address"', $create->body());

        self::assertSame('/customers', $controller->store($this->post(['name' => 'Toko Baru']))->headers()['Location']);

        $edit = $controller->edit(new Request('GET', '/customers/edit', ['id' => '1'], [], []));
        self::assertSame(200, $edit->statusCode());
        self::assertStringContainsString('value="Toko Maju"', $edit->body());
        self::assertStringContainsString('value="0811"', $edit->body());
        $this->assertHttpStatus(404, fn () => $controller->edit(new Request('GET', '/customers/edit', ['id' => '99'], [], [])));

        self::assertSame(302, $controller->update($this->post(['id' => '1', 'name' => 'Toko Maju Jaya', 'email' => 'jaya@example.test', 'phone' => '0855', 'address' => 'Jl. D']))->statusCode());
        self::assertSame(302, $controller->deactivate($this->post(['id' => '1']))->statusCode());
        self::assertSame('/customers', $controller->activate($this->post(['id' => '1']))->headers()['Location']);
        self::assertSame([[1, false], [1, true]], $toggles);
    }

    public function testCustomerUpdateValidationFailureRerendersForm(): void
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('findById')->willReturn(new Customer(1, 'Toko Maju', 'maju@example.test', '0811', 'Jl. A', true));
        $repository->expects(self::never())->method('update');

        $response = $this->customerController($repository, User::ROLE_ADMIN)->update($this->post(['id' => '1', 'name' => '', 'email' => '', 'phone' => '', 'address' => '"<s>addr']));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsStringIgnoringCase('name is required.', $response->body());
        self::assertStringContainsString('&quot;&lt;s&gt;addr', $response->body());
    }

    public function testCustomerServiceRejectsInvalidEmailAndUnauthorizedWrites(): void
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('all')->willReturn([new Customer(1, 'Toko Maju', '', '', '', true)]);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('setActive');
        $service = new CustomerService($repository, new MasterDataAuthorizationService());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $staff = new AuthContext(3, 'wh@example.test', User::ROLE_WAREHOUSE_STAFF);

        self::assertSame('Toko Maju', $service->all($staff)[0]->name());
        try {
            $service->create($admin, 'Toko', 'not-an-email', '', '');
            self::fail('Expected invalid email rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Valid email is required.', $exception->getMessage());
        }
        $this->assertForbidden(fn () => $service->update($staff, 1, 'X', '', '', ''));
        $this->assertForbidden(fn () => $service->setActive($staff, 1, false));
    }

    // ---------------------------------------------------------------- CSV import

    public function testCategoryCsvImportCreatesRowsAndRedirects(): void
    {
        $repository = $this->categoryRepository();
        $controller = new CategoryController(new CategoryService($repository, new MasterDataAuthorizationService()), $repository, $this->guard(User::ROLE_ADMIN), $this->imports());

        $response = $controller->import($this->post(['csv_data' => "name,description\nRaw Material,Inputs\nPackaging,Boxes\n"]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/categories', $response->headers()['Location']);
        self::assertSame(['Finished Goods', 'Raw Material', 'Packaging'], array_map(static fn (Category $c): string => $c->name(), $repository->all()));
    }

    public function testWarehouseCsvImportCreatesRowsAndRedirects(): void
    {
        $repository = $this->createMock(WarehouseRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('create')->willReturnCallback(
            static fn (string $name, string $location, bool $active): Warehouse => new Warehouse(5, $name, $location, $active),
        );
        $controller = new WarehouseController(new WarehouseService($repository, new MasterDataAuthorizationService()), $repository, $this->guard(User::ROLE_ADMIN), $this->imports());

        $response = $controller->import($this->post(['csv_data' => "name,location\nEast,Surabaya\nWest,Bali\n"]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/warehouses', $response->headers()['Location']);
    }

    public function testSupplierCsvImportCreatesRowsAndRedirects(): void
    {
        $repository = $this->createMock(SupplierRepositoryInterface::class);
        $repository->expects(self::once())->method('create')->with('Gamma', 'gamma@example.test', '0833', 'Jl. G', true)
            ->willReturn(new Supplier(3, 'Gamma', 'gamma@example.test', '0833', 'Jl. G', true));
        $controller = new SupplierController(new SupplierService($repository, new MasterDataAuthorizationService()), $repository, $this->guard(User::ROLE_ADMIN), $this->imports());

        $response = $controller->import($this->post(['csv_data' => "name,email,phone,address\nGamma,gamma@example.test,0833,Jl. G\n"]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/suppliers', $response->headers()['Location']);
    }

    public function testCustomerCsvImportCreatesRowsAndRedirects(): void
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->expects(self::once())->method('create')->with('Toko CSV', 'csv@example.test', '0844', 'Jl. H', true)
            ->willReturn(new Customer(4, 'Toko CSV', 'csv@example.test', '0844', 'Jl. H', true));
        $controller = new CustomerController(new CustomerService($repository, new MasterDataAuthorizationService()), $repository, $this->guard(User::ROLE_ADMIN), $this->imports());

        $response = $controller->import($this->post(['csv_data' => "name,email,phone,address\nToko CSV,csv@example.test,0844,Jl. H\n"]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/customers', $response->headers()['Location']);
    }

    // ---------------------------------------------------------------- helpers

    private function categoryRepository(): InMemoryCategoryRepository
    {
        return new InMemoryCategoryRepository([new Category(1, 'Finished Goods', 'Ready-to-sell inventory', true)]);
    }

    private function categoryController(InMemoryCategoryRepository $repository, string $role): CategoryController
    {
        return new CategoryController(new CategoryService($repository, new MasterDataAuthorizationService()), $repository, $this->guard($role));
    }

    private function warehouseController(WarehouseRepositoryInterface $repository, string $role): WarehouseController
    {
        return new WarehouseController(new WarehouseService($repository, new MasterDataAuthorizationService()), $repository, $this->guard($role));
    }

    private function supplierController(SupplierRepositoryInterface $repository, string $role): SupplierController
    {
        return new SupplierController(new SupplierService($repository, new MasterDataAuthorizationService()), $repository, $this->guard($role));
    }

    private function customerController(CustomerRepositoryInterface $repository, string $role): CustomerController
    {
        return new CustomerController(new CustomerService($repository, new MasterDataAuthorizationService()), $repository, $this->guard($role));
    }

    private function imports(): \App\Service\CsvImportService
    {
        return new \App\Service\CsvImportService(new \Tests\Support\ImmediateTransactions());
    }

    private function guard(string $role): AuthGuard
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'actor@example.test', $role));

        return new AuthGuard($session);
    }

    /** @param array<string, string> $post */
    private function post(array $post): Request
    {
        return new Request('POST', '/', [], $post, []);
    }

    private function assertForbidden(callable $call): void
    {
        $this->assertHttpStatus(403, $call);
    }

    private function assertHttpStatus(int $status, callable $call): void
    {
        try {
            $call();
            self::fail("Expected HTTP {$status}.");
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->statusCode());
        }
    }
}
