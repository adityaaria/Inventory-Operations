<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\ReportService;
use App\Support\CsvResponse;
use InvalidArgumentException;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->guard->requireAuth();

        return Response::html((string) file_get_contents(dirname(__DIR__, 2) . '/views/reports/index.php'));
    }

    public function stockLedger(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        if ($actor->role() === User::ROLE_SALES) {
            throw new HttpException(403, 'Forbidden');
        }

        try {
            return CsvResponse::download('stock-ledger.csv', $this->reports->stockLedgerCsv($this->date($request, 'from'), $this->date($request, 'to')));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    public function orders(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $salesUserId = $actor->role() === User::ROLE_SALES ? $actor->userId() : null;

        try {
            return CsvResponse::download('orders.csv', $this->reports->ordersCsv($this->date($request, 'from'), $this->date($request, 'to'), $salesUserId));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    private function date(Request $request, string $key): ?string
    {
        $value = trim((string) ($request->query()[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
