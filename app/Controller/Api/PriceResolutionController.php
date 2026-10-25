<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\PriceResolver;
use InvalidArgumentException;

/**
 * GET /api/price-resolution?customer_id&product_id&unit_code&quantity (D-19, ADR-025): the list price a new SO or
 * quotation line would get (customer price list tier or master) for the form's live display. JSON; 401 without a
 * session, 403 for Warehouse, 422 for invalid input. The server re-resolves every line on save.
 */
final class PriceResolutionController
{
    public function __construct(private readonly PriceResolver $prices, private readonly AuthGuard $guard)
    {
    }

    public function show(Request $request): Response
    {
        try {
            $actor = $this->guard->requireAuth();
        } catch (HttpException) {
            return $this->json(['error' => 'Authentication required'], 401);
        }
        try {
            return $this->json($this->prices->lookup($actor, $request->query()));
        } catch (HttpException $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        } catch (InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return new Response((string) json_encode($payload, JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
}
