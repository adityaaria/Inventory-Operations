<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\DashboardService;

final class DashboardController
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(): Response
    {
        return $this->render('dashboard/index.php', ['dashboard' => $this->dashboard->forActor($this->guard->requireAuth())]);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = []): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '');
    }
}
