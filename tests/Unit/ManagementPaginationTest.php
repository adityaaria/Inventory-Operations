<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\UserController;
use App\Entity\Category;
use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\UserService;
use App\Support\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagementPaginationTest extends TestCase
{
    public static function invalidPages(): array
    {
        return [[0], [-1], ['bad'], ['1 OR 1=1'], ['999999999999999999999999'], [['2']], [null]];
    }

    #[DataProvider('invalidPages')]
    public function testMalformedPagesNormalizeToFirstPage(mixed $page): void
    {
        self::assertSame(1, Pagination::fromArray(['page' => $page])->pageForTotal(25));
    }

    public function testFixedPageSizeAndOutOfRangeClamping(): void
    {
        $pagination = Pagination::fromArray(['page' => '999', 'per_page' => 1000]);
        self::assertSame(10, Pagination::PER_PAGE);
        self::assertSame(3, $pagination->pageForTotal(23));
        self::assertSame(20, $pagination->offsetForTotal(23));
        self::assertSame(1, $pagination->pageForTotal(0));
        self::assertSame(0, $pagination->offsetForTotal(0));
    }

    public function testPagesHaveNoOverlapsAndIncludeInactiveUsers(): void
    {
        $repository = $this->users();
        $first = $repository->paginate(new Pagination(1));
        $second = $repository->paginate(new Pagination(2));
        $last = $repository->paginate(new Pagination(999));
        self::assertCount(10, $first->items());
        self::assertCount(10, $second->items());
        self::assertCount(3, $last->items());
        self::assertSame(23, $first->total());
        self::assertSame(3, $last->page());
        self::assertSame(range(11, 20), array_map(fn (User $user): int => $user->id(), $second->items()));
        self::assertFalse($first->items()[0]->isActive());
    }

    public function testCategoryOrderingUsesNameThenIdAndEmptyPageIsUsable(): void
    {
        $repository = new InMemoryCategoryRepository([
            new Category(3, 'Z', '', true), new Category(2, 'A', '', true), new Category(1, 'A', '', false),
        ]);
        self::assertSame([1, 2, 3], array_map(fn (Category $category): int => $category->id(), $repository->paginate(new Pagination())->items()));
        $empty = (new InMemoryCategoryRepository())->paginate(new Pagination(2));
        self::assertSame([], $empty->items());
        self::assertSame(1, $empty->page());
        self::assertSame(1, $empty->pages());
    }

    public function testSalesCannotReadPaginatedUsers(): void
    {
        $this->expectException(HttpException::class);
        (new UserService($this->users()))->paginate(new AuthContext(2, 'sales@example.test', User::ROLE_SALES), new Pagination());
    }

    public function testControllerRendersPageTwoAndKeepsQueryParameters(): void
    {
        $repository = $this->users();
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $controller = new UserController(new UserService($repository), $repository, new AuthGuard($session), new \App\Service\CsvImportService(new \Tests\Support\ImmediateTransactions()));
        $response = $controller->index(new Request('GET', '/users', ['page' => '2', 'context' => 'a&b'], [], []));
        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Page 2 of 3', $response->body());
        self::assertStringContainsString('/users?page=3&amp;context=a%26b', $response->body());
        self::assertStringContainsString('User 11', $response->body());
        self::assertStringNotContainsString('User 1</td>', $response->body());
    }

    public function testFailedImportRendersPaginationWithoutCrashing(): void
    {
        $repository = $this->users();
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $controller = new UserController(new UserService($repository), $repository, new AuthGuard($session), new \App\Service\CsvImportService(new \Tests\Support\ImmediateTransactions()));
        $response = $controller->import(new Request('POST', '/users/import', ['page' => '2'], ['csv_data' => "name,email,password,role\nInvalid,bad,password,Sales\n"], []));
        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Page 2 of 3', $response->body());
    }

    private function users(): InMemoryUserRepository
    {
        $users = [];
        for ($id = 23; $id >= 1; $id--) {
            $users[] = new User($id, 'User ' . $id, 'u' . $id . '@example.test', 'hash', User::ROLE_SALES, $id !== 1);
        }
        return new InMemoryUserRepository($users);
    }
}
