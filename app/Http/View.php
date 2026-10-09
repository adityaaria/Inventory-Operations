<?php

declare(strict_types=1);

namespace App\Http;

final class View
{
    /**
     * Renders a template from views/ with exactly the given variables in scope.
     *
     * @param array<string, mixed> $variables
     */
    public static function render(string $template, array $variables = [], int $status = 200): Response
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $template;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $status);
    }
}
