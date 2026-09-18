<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\ProductAvailabilityService;

final class ProductAvailabilityController
{
    public function __construct(
        private readonly ProductAvailabilityService $availability,
        private readonly AuthGuard $guard,
    ) {
    }

    public function show(Request $request): Response
    {
        try {
            $this->guard->requireAuth();
        } catch (HttpException) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        $sku = (string) ($request->query()['sku'] ?? '');
        $availability = $this->availability->forSku($sku);
        if ($availability === null) {
            return $this->json(['error' => 'Product not found'], 404);
        }

        return $this->json($availability);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return new Response((string) json_encode($payload), $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
}
