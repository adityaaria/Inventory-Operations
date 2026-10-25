<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\BarcodeService;
use InvalidArgumentException;

/**
 * GET /api/barcodes/{code} (D-18, ADR-024). Same conventions as the availability API: JSON body, `{"error": ...}` on
 * failure, 401 without a session, 403 for roles without scanning work (Sales), 404 unknown code, 422 malformed code.
 */
final class BarcodeController
{
    public function __construct(private readonly BarcodeService $barcodes, private readonly AuthGuard $guard)
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
            return $this->json($this->barcodes->lookup($actor, $request->query()['code'] ?? ''));
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
