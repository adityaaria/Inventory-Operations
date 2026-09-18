<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ValidationException;
use App\Http\ErrorResponder;
use PHPUnit\Framework\TestCase;

final class ErrorResponseTest extends TestCase
{
    public function testBrowserValidationErrorIsSafeHtml422(): void
    {
        $response = ErrorResponder::browser(new ValidationException('Bad <input>'), false);

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Bad &lt;input&gt;', $response->body());
    }

    public function testApiErrorIsJson(): void
    {
        $response = ErrorResponder::api('Missing product', 404);

        self::assertSame(404, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('{"error":"Missing product"}', $response->body());
    }
}
