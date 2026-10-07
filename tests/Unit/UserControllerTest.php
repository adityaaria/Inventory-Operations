<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\UserController;
use App\Entity\User;
use App\Http\Request;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserControllerTest extends TestCase
{
    public function testAdminCanImportUserCsv(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $repository = new InMemoryUserRepository();
        $controller = new UserController(new UserService($repository), $repository, new AuthGuard($session), new \App\Service\CsvImportService(new \Tests\Support\ImmediateTransactions()));

        $response = $controller->import(new Request('POST', '/users/import', [], [
            'csv_data' => "name,email,password,role\nImported User,imported@example.test,password123,Sales\n",
        ], []));

        self::assertSame(302, $response->statusCode());
        self::assertSame('Imported User', $repository->findByEmail('imported@example.test')?->name());
    }
}
