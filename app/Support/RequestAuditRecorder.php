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

        $parts = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        if ($parts === []) {
            return;
        }

        $entityType = $parts[0];
        $operation = $parts[1] ?? 'create';
        $status = $response->statusCode() >= 200 && $response->statusCode() < 400 ? 'success' : 'failure';
        $entityId = isset($request->post()['id']) && (int) $request->post()['id'] > 0 ? (int) $request->post()['id'] : null;

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
