<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\Api\ProductAvailabilityController;
use App\Entity\User;
use App\Http\Request;
use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\ProductAvailabilityService;
use PHPUnit\Framework\TestCase;

final class ProductAvailabilityApiTest extends TestCase
{
    public function testExistingSkuReturnsJsonShape(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $response = $this->controller($session)->show(new Request('GET', '/api/products/SKU-001/availability', ['sku' => 'SKU-001'], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertStringContainsString('"sku":"SKU-001"', $response->body());
    }

    public function testUnauthenticatedReturnsJson401(): void
    {
        $response = $this->controller(new SessionManager())->show(new Request('GET', '/api/products/SKU-001/availability', ['sku' => 'SKU-001'], [], []));

        self::assertSame(401, $response->statusCode());
        self::assertStringContainsString('"error":"Authentication required"', $response->body());
    }

    public function testMissingSkuReturnsJson404(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $response = $this->controller($session)->show(new Request('GET', '/api/products/MISSING/availability', ['sku' => 'MISSING'], [], []));

        self::assertSame(404, $response->statusCode());
    }

    private function controller(SessionManager $session): ProductAvailabilityController
    {
        return new ProductAvailabilityController(new ProductAvailabilityService(new InMemoryOperationalQueryRepository()), new AuthGuard($session));
    }
}
