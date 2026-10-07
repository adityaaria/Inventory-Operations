<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\DraftCheckService;
use App\Validation\InputValidator;
use InvalidArgumentException;

final class DraftCheckController
{
    public function __construct(
        private readonly DraftCheckService $drafts,
        private readonly AuthGuard $guard,
    ) {
    }

    public function check(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $query = $request->query();
        try {
            $form = $query['form'] ?? '';
            $warehouse = $query['warehouse_id'] ?? '';
            $products = $query['product_ids'] ?? [];
            if (!is_string($form) || !is_array($products)) {
                throw new ValidationException('Invalid draft check.');
            }
            $result = $this->drafts->check(
                $actor,
                $form,
                $warehouse === '' ? null : InputValidator::positiveInt('warehouse_id', $warehouse),
                array_values(array_map(static fn (mixed $id): int => InputValidator::positiveInt('product_id', $id), $products)),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json($result, 200);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status): Response
    {
        return new Response(json_encode($data, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
