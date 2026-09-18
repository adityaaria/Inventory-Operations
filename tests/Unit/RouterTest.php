<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesMatchingGetRoute(): void
    {
        $router = new Router();
        $router->get('/', static fn (Request $request): Response => Response::html('ok'));

        $response = $router->dispatch(new Request('GET', '/', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertSame('ok', $response->body());
    }

    public function testUnknownRouteThrowsHttp404(): void
    {
        $router = new Router();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Route not found.');

        $router->dispatch(new Request('GET', '/missing', [], [], []));
    }
}
