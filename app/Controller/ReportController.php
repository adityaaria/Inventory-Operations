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
use App\Support\Pagination;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $from = $to = null;
        $type = $actor->role() === User::ROLE_WAREHOUSE_STAFF ? 'stock-ledger' : 'orders';
        $error = '';
        $status = 200;
        $filters = ['document' => '', 'age' => ''];
        try {
            $requestedType = $request->query()['type'] ?? $type;
            if (!is_string($requestedType)) { throw new InvalidArgumentException('Invalid report type.'); }
            $type = $requestedType;
            $from = $this->date($request, 'from');
            $to = $this->date($request, 'to');
            if ($type === 'outstanding') {
                $filters = ['document' => $request->query()['document'] ?? '', 'age' => $request->query()['age'] ?? ''];
            }
            $report = $this->reports->preview($actor, $type, $from, $to, Pagination::fromArray($request->query()), $filters);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $status = 422;
            $from = $to = null;
            $type = $actor->role() === User::ROLE_WAREHOUSE_STAFF ? 'stock-ledger' : 'orders';
            $filters = ['document' => '', 'age' => ''];
            $report = null;
        }
        $outstandingDocuments = $this->reports->outstandingDocuments($actor);
        $filterQuery = $type === 'outstanding' ? array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $filters) : [];
        $canViewStock = $actor->role() !== User::ROLE_SALES;
        $paginationPath = '/reports';
        $paginationLabel = 'Report records';
        $paginationQuery = ['type' => $type, 'from' => $from ?? '', 'to' => $to ?? ''] + $filterQuery;
        $result = $report['result'] ?? null;
        $exportUrl = '/reports/' . $type . '.csv?' . http_build_query(['from' => $from ?? '', 'to' => $to ?? ''] + $filterQuery);
        ob_start();
        require dirname(__DIR__, 2) . '/views/reports/index.php';
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $status);
    }

    public function stockLedger(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        if ($actor->role() === User::ROLE_SALES) {
            throw new HttpException(403, 'Forbidden');
        }

        try {
            return CsvResponse::download('stock-ledger.csv', $this->reports->csvStream($actor, 'stock-ledger', $this->date($request, 'from'), $this->date($request, 'to')));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    public function orders(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        try {
            return CsvResponse::download('orders.csv', $this->reports->csvStream($actor, 'orders', $this->date($request, 'from'), $this->date($request, 'to')));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    public function outstanding(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        try {
            $filters = ['document' => $request->query()['document'] ?? '', 'age' => $request->query()['age'] ?? ''];

            return CsvResponse::download('outstanding-orders.csv', $this->reports->csvStream($actor, 'outstanding', $this->date($request, 'from'), $this->date($request, 'to'), $filters));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    private function date(Request $request, string $key): ?string
    {
        $input = $request->query()[$key] ?? '';
        if (!is_string($input)) { throw new InvalidArgumentException('Invalid date range.'); }
        $value = trim($input);

        return $value === '' ? null : $value;
    }
}
