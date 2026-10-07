<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Security\{AuthContext, AuthGuard, SessionManager};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MasterFormRetentionTest extends TestCase
{
    public static function modules(): array
    {
        return [['Category','categories','description'],['Warehouse','warehouses','location'],['Supplier','suppliers','address'],['Customer','customers','address']];
    }

    #[DataProvider('modules')]
    public function testFailedCreateRetainsEscapedDetailWithoutInserting(string $type, string $route, string $field): void
    {
        $session = new SessionManager(); $session->login(new AuthContext(1,'admin@test','Admin'));
        $repositoryClass = 'App\\Repository\\Contract\\' . $type . 'RepositoryInterface';
        $serviceClass = 'App\\Service\\' . $type . 'Service';
        $controllerClass = 'App\\Controller\\' . $type . 'Controller';
        $repository = $this->createMock($repositoryClass);
        $repository->expects($this->never())->method('create');
        $service = new $serviceClass($repository, new \App\Service\MasterDataAuthorizationService());
        $controller = new $controllerClass($service, $repository, new AuthGuard($session));
        $response = $controller->store(new Request('POST','/'.$route,[],['name'=>'','email'=>'contact@test.example','phone'=>'123456',$field=>'"<script>KEEP'],[]));
        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('&quot;&lt;script&gt;KEEP', $response->body());
        self::assertStringNotContainsString('"<script>KEEP', $response->body());
    }
}
