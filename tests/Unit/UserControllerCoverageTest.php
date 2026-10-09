<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\UserController;
use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\EntityNotFoundException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserControllerCoverageTest extends TestCase
{
    private InMemoryUserRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryUserRepository([
            new User(1, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_BCRYPT), User::ROLE_ADMIN, true),
            new User(2, 'Sally Sales', 'sales@example.test', password_hash('password123', PASSWORD_BCRYPT), User::ROLE_SALES, true),
        ]);
    }

    public function testAdminSeesCreateFormWithAllRoles(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->create();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('action="/users"', $response->body());
        foreach (User::ROLES as $role) {
            self::assertStringContainsString('value="' . $role . '"', $response->body());
        }
    }

    public function testNonAdminCannotOpenCreateForm(): void
    {
        $this->expectExceptionObject(new HttpException(403, 'Forbidden'));

        $this->controller(User::ROLE_SALES)->create();
    }

    public function testStoreCreatesUserAndRedirects(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->store($this->post([
            'name' => 'New Clerk',
            'email' => 'Clerk@Example.test',
            'password' => 'password123',
            'role' => User::ROLE_WAREHOUSE_STAFF,
        ]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/users', $response->headers()['Location']);
        $created = $this->repository->findByEmail('clerk@example.test');
        self::assertNotNull($created);
        self::assertSame(User::ROLE_WAREHOUSE_STAFF, $created->role());
    }

    public function testStoreWithShortPasswordRerendersFormWithEscapedOldInput(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->store($this->post([
            'name' => '"<b>Kept</b>',
            'email' => 'kept@example.test',
            'password' => 'short',
            'role' => User::ROLE_SALES,
        ]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Password must be at least 8 characters.', $response->body());
        self::assertStringContainsString('&quot;&lt;b&gt;Kept&lt;/b&gt;', $response->body());
        self::assertNull($this->repository->findByEmail('kept@example.test'));
    }

    public function testEditRendersExistingUser(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->edit(new Request('GET', '/users/edit', ['id' => '2'], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('value="Sally Sales"', $response->body());
        self::assertStringContainsString('value="sales@example.test"', $response->body());
        self::assertStringContainsString('name="id" value="2"', $response->body());
    }

    public function testEditUnknownUserReturns404(): void
    {
        try {
            $this->controller(User::ROLE_ADMIN)->edit(new Request('GET', '/users/edit', ['id' => '999'], [], []));
            self::fail('Expected 404.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode());
            self::assertSame('User not found.', $exception->getMessage());
        }
    }

    public function testUpdateChangesUserAndRedirects(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->update($this->post([
            'id' => '2',
            'name' => 'Sally Renamed',
            'email' => 'SALLY@example.test',
            'role' => User::ROLE_WAREHOUSE_STAFF,
        ]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/users', $response->headers()['Location']);
        $user = $this->repository->findById(2);
        self::assertSame('Sally Renamed', $user?->name());
        self::assertSame('sally@example.test', $user?->email());
        self::assertSame(User::ROLE_WAREHOUSE_STAFF, $user?->role());
    }

    public function testUpdateWithInvalidEmailRerendersEditFormKeepingInput(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->update($this->post([
            'id' => '2',
            'name' => 'Attempted Name',
            'email' => 'not-an-email',
            'role' => User::ROLE_SALES,
        ]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Valid email is required.', $response->body());
        self::assertStringContainsString('value="Attempted Name"', $response->body());
        self::assertSame('Sally Sales', $this->repository->findById(2)?->name());
    }

    public function testUpdateFailureForMissingUserShowsNotFoundMessage(): void
    {
        $response = $this->controller(User::ROLE_ADMIN)->update($this->post([
            'id' => '999',
            'name' => '',
            'email' => 'ghost@example.test',
            'role' => User::ROLE_SALES,
        ]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Name is required.', $response->body());
        self::assertStringContainsString('User not found.', $response->body());
        self::assertStringNotContainsString('action="/users/update"', $response->body());
    }

    public function testUpdateOfMissingUserWithValidInputFailsInRepository(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->controller(User::ROLE_ADMIN)->update($this->post(['id' => '999', 'name' => 'Ghost', 'email' => 'ghost@example.test', 'role' => User::ROLE_SALES]));
    }

    public function testUpdateRejectsNonPositiveId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->controller(User::ROLE_ADMIN)->update($this->post(['id' => '0', 'name' => 'X', 'email' => 'x@example.test', 'role' => User::ROLE_SALES]));
    }

    public function testDeactivateAndActivateToggleUser(): void
    {
        $controller = $this->controller(User::ROLE_ADMIN);

        $response = $controller->deactivate($this->post(['id' => '2']));
        self::assertSame(302, $response->statusCode());
        self::assertSame('/users', $response->headers()['Location']);
        self::assertFalse($this->repository->findById(2)?->isActive());

        $response = $controller->activate($this->post(['id' => '2']));
        self::assertSame(302, $response->statusCode());
        self::assertTrue($this->repository->findById(2)?->isActive());
    }

    public function testActivateUnknownUserFails(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->controller(User::ROLE_ADMIN)->activate($this->post(['id' => '999']));
    }

    public function testNonAdminCannotWriteUsers(): void
    {
        $controller = $this->controller(User::ROLE_WAREHOUSE_STAFF);
        $calls = [
            fn () => $controller->store($this->post(['name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', 'role' => User::ROLE_SALES])),
            fn () => $controller->edit(new Request('GET', '/users/edit', ['id' => '2'], [], [])),
            fn () => $controller->update($this->post(['id' => '2', 'name' => 'X', 'email' => 'x@example.test', 'role' => User::ROLE_SALES])),
            fn () => $controller->activate($this->post(['id' => '2'])),
            fn () => $controller->deactivate($this->post(['id' => '2'])),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected 403.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
        }

        self::assertNull($this->repository->findByEmail('x@example.test'));
        self::assertTrue($this->repository->findById(2)?->isActive());
        self::assertSame('Sally Sales', $this->repository->findById(2)?->name());
    }

    /** @param array<string, string> $post */
    private function post(array $post): Request
    {
        return new Request('POST', '/users', [], $post, []);
    }

    private function controller(string $role): UserController
    {
        $session = new SessionManager();
        $session->login(new AuthContext($role === User::ROLE_ADMIN ? 1 : 2, 'actor@example.test', $role));

        return new UserController(new UserService($this->repository), $this->repository, new AuthGuard($session));
    }
}
