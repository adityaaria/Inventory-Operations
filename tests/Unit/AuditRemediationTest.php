<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Controller\ProductController;
use App\Entity\{User, Product, Category};
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\{InMemoryUserRepository, InMemoryProductRepository, InMemoryCategoryRepository};
use App\Security\{AuthContext, AuthGuard, SessionManager};
use App\Service\{AuthService, ProductService, MasterDataAuthorizationService};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditRemediationTest extends TestCase
{
    public function testIntegrationConnectionRefusesApplicationDatabaseBeforeConnecting(): void
    {
        $previous = getenv('DB_DATABASE');
        putenv('DB_DATABASE=inventory_order_management');
        try {
            \Tests\Support\TestDatabase::connect();
            self::fail('Application database must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('dedicated DB_DATABASE', $exception->getMessage());
        } finally { putenv($previous === false ? 'DB_DATABASE' : 'DB_DATABASE=' . $previous); }
    }
    public function testRoleDowngradeRevokesAdminAccessOnExistingSession(): void
    {
        [$users, $session] = $this->authenticatedAdmin();
        $users->update(1, 'Admin', 'admin@example.test', User::ROLE_SALES);
        $guard = new AuthGuard($session, $users);
        self::assertSame(User::ROLE_SALES, $guard->requireAuth()->role());
        $this->expectException(HttpException::class);
        $guard->requireUserManagement();
    }

    public function testDeactivatedUserIsLoggedOutOnNextAuthenticatedRequest(): void
    {
        [$users, $session] = $this->authenticatedAdmin();
        $users->setActive(1, false);
        try { (new AuthGuard($session, $users))->requireAuth(); self::fail('Inactive session must be rejected.'); }
        catch (HttpException $exception) { self::assertSame(401, $exception->statusCode()); }
        self::assertNull($session->auth());
    }

    public static function malformedProductValues(): array
    {
        return [['purchase_price', 'abc'], ['selling_price', ['bad']], ['reorder_point', '2.9'], ['reorder_point', -1], ['category_id', ['bad']], ['name', ['bad']]];
    }

    #[DataProvider('malformedProductValues')]
    public function testRawMalformedInputsProduce422AndNoInsert(string $field, mixed $value): void
    {
        $repository = new InMemoryProductRepository();
        $session = new SessionManager(); $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));
        $controller = new ProductController(new ProductService($repository, new MasterDataAuthorizationService()), $repository, new InMemoryCategoryRepository(), new AuthGuard($session));
        $post = ['sku' => 'TEST', 'name' => 'Test', 'unit' => 'pcs', 'purchase_price' => '0', 'selling_price' => '1', 'reorder_point' => '0', 'category_id' => '1'];
        $post[$field] = $value;
        self::assertSame(422, $controller->store(new Request('POST', '/products', [], $post, []))->statusCode());
        self::assertSame(0, $repository->search(\App\Support\ProductSearchCriteria::fromArray([]))->total());
    }

    public function testSalesCanReadProductDetailButCannotEdit(): void
    {
        $repository = new InMemoryProductRepository([new Product(1, 'TEST', '<script>Unsafe</script>', 'pcs', 0, 2, 5, 1, true)]);
        $repository->setStockSnapshot(1, 1, 7);
        $session = new SessionManager(); $session->login(new AuthContext(2, 'sales@test', User::ROLE_SALES));
        $controller = new ProductController(new ProductService($repository, new MasterDataAuthorizationService()), $repository, new InMemoryCategoryRepository([new Category(1, 'Test', '', true)]), new AuthGuard($session));
        $response = $controller->show(new Request('GET', '/products/show', ['id' => '1'], [], []));
        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('&lt;script&gt;Unsafe&lt;/script&gt;', $response->body());
        self::assertStringContainsString('Total Stock', $response->body());
        self::assertStringNotContainsString('Edit Product</a>', $response->body());
        $this->expectException(HttpException::class);
        $controller->edit(new Request('GET', '/products/edit', ['id' => '1'], [], []));
    }

    private function authenticatedAdmin(): array
    {
        $users = new InMemoryUserRepository([new User(1, 'Admin', 'admin@example.test', password_hash('password', PASSWORD_BCRYPT), User::ROLE_ADMIN, true)]);
        $session = new SessionManager();
        self::assertTrue((new AuthService($users, $session))->login('admin@example.test', 'password'));
        return [$users, $session];
    }
}
