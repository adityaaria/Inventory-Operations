<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Request;
use App\Http\Response;
use App\Security\AuthContext;
use App\Service\AuditLogger;

final class RequestAuditRecorder
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function record(Request $request, Response $response, ?AuthContext $actor): void
    {
        if ($request->method() !== 'POST' || in_array($request->path(), ['/login', '/logout'], true)) {
            return;
        }

        if ($response->statusCode() < 400 && in_array($request->path(), ['/purchase-orders/receive', '/sales-orders/issue'], true)) {
            return; // Success is recorded atomically with the stock movement.
        }
        $parts = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        if ($parts === []) {
            return;
        }

        $entityType = $parts[0];
        $operation = $parts[1] ?? 'create';
        $status = $response->statusCode() >= 200 && $response->statusCode() < 400 ? 'success' : 'failure';
        $rawId = $request->post()['id'] ?? null;
        $validatedId = is_int($rawId) || is_string($rawId)
            ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $entityId = is_int($validatedId) ? $validatedId : null;

        $this->audit->record(
            $actor?->userId(),
            $entityType . '.' . $operation,
            $entityType,
            $entityId,
            $status,
            $this->serverValue($request, 'REMOTE_ADDR', 45),
            $this->serverValue($request, 'HTTP_USER_AGENT', 255),
            ['path' => $request->path(), 'status_code' => $response->statusCode()],
        );
    }

    private function serverValue(Request $request, string $key, int $limit): string
    {
        $value = $request->server()[$key] ?? '';

        return is_string($value) ? substr($value, 0, $limit) : '';
    }
}
