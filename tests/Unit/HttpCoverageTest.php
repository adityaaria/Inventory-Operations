<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ForbiddenException;
use App\Exception\HttpException;
use App\Exception\UnauthenticatedException;
use App\Http\ErrorResponder;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HttpCoverageTest extends TestCase
{
    public function testRouterDispatchesPostRoutesSeparatelyFromGet(): void
    {
        $router = new Router();
        $router->post('/login', static fn (Request $request): Response => Response::html('posted:' . (string) $request->post()['email']));

        self::assertSame('posted:a@b.test', $router->dispatch(new Request('POST', '/login', [], ['email' => 'a@b.test'], []))->body());

        $this->expectException(HttpException::class);
        $router->dispatch(new Request('GET', '/login', [], [], []));
    }

    public function testRouterResolvesProductAvailabilityPlaceholderWithDecodedSku(): void
    {
        $router = new Router();
        $router->get('/api/products/{sku}/availability', static fn (Request $request): Response => Response::html(
            (string) $request->query()['sku'] . '|' . (string) $request->query()['warehouse_id'] . '|' . $request->path() . '|' . (string) $request->server()['REMOTE_ADDR']
        ));

        $response = $router->dispatch(new Request('GET', '/api/products/SKU%2001/availability', ['warehouse_id' => '3'], [], ['REMOTE_ADDR' => '127.0.0.1']));

        self::assertSame('SKU 01|3|/api/products/SKU%2001/availability|127.0.0.1', $response->body());
    }

    public function testRouterDoesNotUseAvailabilityPlaceholderForOtherMethodsOrUnregisteredRoute(): void
    {
        $router = new Router();
        try {
            $router->dispatch(new Request('GET', '/api/products/SKU-1/availability', [], [], []));
            self::fail('Expected 404 without a registered availability route.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode());
        }

        $router->get('/api/products/{sku}/availability', static fn (): Response => Response::html('never'));
        $this->expectException(HttpException::class);
        $router->dispatch(new Request('POST', '/api/products/SKU-1/availability', [], [], []));
    }

    #[RunInSeparateProcess]
    public function testRequestFromGlobalsNormalizesMethodAndPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/products?page=2';
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_GET = ['page' => '2'];
        $_POST = ['name' => 'Widget'];
        $_FILES = ['csv_file' => ['error' => UPLOAD_ERR_NO_FILE]];

        $request = Request::fromGlobals();

        self::assertSame('POST', $request->method());
        self::assertSame('/products', $request->path());
        self::assertSame(['page' => '2'], $request->query());
        self::assertSame(['name' => 'Widget'], $request->post());
        self::assertSame('/products?page=2', $request->server()['REQUEST_URI']);
        self::assertSame(['csv_file' => ['error' => UPLOAD_ERR_NO_FILE]], $request->files());
    }

    #[RunInSeparateProcess]
    public function testRequestFromGlobalsFallsBackToGetAndRootPath(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        self::assertSame(['GET', '/'], [Request::fromGlobals()->method(), Request::fromGlobals()->path()]);

        $_SERVER['REQUEST_URI'] = '?only=query';
        self::assertSame('/', Request::fromGlobals()->path());

        $_SERVER['REQUEST_URI'] = 'http://:80';
        self::assertSame('/', Request::fromGlobals()->path());
    }

    #[RunInSeparateProcess]
    public function testSendStreamsClosureBodies(): void
    {
        $response = new Response(static function (): void {
            echo 'chunk-1;';
            echo 'chunk-2';
        }, 201, ['Content-Type' => 'text/csv']);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame('chunk-1;chunk-2', $output);
        self::assertSame(201, http_response_code());
    }

    /** @return iterable<string, array{\Throwable, int, string}> */
    public static function statusTitles(): iterable
    {
        yield 'unauthenticated' => [new UnauthenticatedException(), 401, 'Authentication Required'];
        yield 'forbidden' => [new ForbiddenException(), 403, 'Forbidden'];
        yield 'http 404' => [new HttpException(404, 'Route not found.'), 404, 'Not Found'];
        yield 'http 409' => [new HttpException(409, 'Stock changed.'), 409, 'Conflict'];
        yield 'http 422' => [new HttpException(422, 'Bad input.'), 422, 'Validation Error'];
        yield 'plain throwable' => [new \LogicException('internal detail'), 500, 'Server Error'];
    }

    #[DataProvider('statusTitles')]
    public function testBrowserErrorUsesStatusSpecificTitle(\Throwable $throwable, int $status, string $title): void
    {
        $response = ErrorResponder::browser($throwable, false);

        self::assertSame($status, $response->statusCode());
        self::assertStringContainsString('<h1>' . $title . '</h1>', $response->body());
        if ($status === 500) {
            self::assertStringContainsString('Unexpected server error.', $response->body());
            self::assertStringNotContainsString('internal detail', $response->body());
        } else {
            self::assertStringContainsString(htmlspecialchars($throwable->getMessage(), ENT_QUOTES, 'UTF-8'), $response->body());
        }
    }

    public function testDebugModeShowsEscapedMessageForServerErrors(): void
    {
        $body = ErrorResponder::browser(new \RuntimeException('<b>boom</b>'), true)->body();

        self::assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $body);
    }

    public function testExceptionsCarryDefaultAndCustomMessages(): void
    {
        self::assertSame('Forbidden', (new ForbiddenException())->getMessage());
        self::assertSame('Admins only.', (new ForbiddenException('Admins only.'))->getMessage());
        self::assertSame(403, (new ForbiddenException())->statusCode());
        self::assertSame('Authentication required.', (new UnauthenticatedException())->getMessage());
        self::assertSame('Login first.', (new UnauthenticatedException('Login first.'))->getMessage());
        self::assertSame(401, (new UnauthenticatedException())->statusCode());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function errorTemplates(): iterable
    {
        yield '403' => ['errors/403.php', 'Forbidden', 'You are not allowed to access this page.'];
        yield '404' => ['errors/404.php', 'Not Found', 'The requested resource was not found.'];
        yield '500' => ['errors/500.php', 'Server Error', 'Unexpected server error.'];
    }

    #[DataProvider('errorTemplates')]
    public function testStaticErrorTemplatesRenderStandaloneAccessiblePages(string $template, string $title, string $alert): void
    {
        $body = View::render($template, [], 418)->body();

        self::assertStringStartsWith('<!doctype html>', $body);
        self::assertStringContainsString('<title>' . $title . '</title>', $body);
        self::assertStringContainsString('<h1>' . $title . '</h1>', $body);
        self::assertStringContainsString('<p class="alert alert-danger" role="alert">' . $alert . '</p>', $body);
        self::assertStringContainsString('href="/">Back to dashboard</a>', $body);
        self::assertStringNotContainsString('<?', $body);
    }
}
