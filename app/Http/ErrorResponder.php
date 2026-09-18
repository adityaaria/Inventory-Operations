<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\HttpException;

final class ErrorResponder
{
    public static function browser(\Throwable $throwable, bool $debug): Response
    {
        $status = self::statusCode($throwable);
        $message = $debug ? $throwable->getMessage() : self::safeMessage($status, $throwable->getMessage());

        return Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Error</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="page"><h1>'
            . self::title($status)
            . '</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p></main></body></html>',
            $status,
        );
    }

    public static function api(string $message, int $status): Response
    {
        return new Response((string) json_encode(['error' => $message]), $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    private static function title(int $status): string
    {
        return match ($status) {
            401 => 'Authentication Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Validation Error',
            default => 'Server Error',
        };
    }

    private static function statusCode(\Throwable $throwable): int
    {
        if ($throwable instanceof HttpException) {
            return $throwable->statusCode();
        }

        if (method_exists($throwable, 'statusCode')) {
            $status = $throwable->statusCode();

            return is_int($status) ? $status : 500;
        }

        return 500;
    }

    private static function safeMessage(int $status, string $message): string
    {
        return in_array($status, [401, 403, 404, 409, 422], true) ? $message : 'Unexpected server error.';
    }
}
