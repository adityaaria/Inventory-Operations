<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\LotService;
use App\Support\CsvResponse;
use App\Validation\InputValidator;
use InvalidArgumentException;

/** Expiry report and lot recall/trace (D-17, ADR-023). HTTP only; LotService authorizes. */
final class LotController
{
    public function __construct(private readonly LotService $lots, private readonly AuthGuard $guard, private readonly WarehouseRepositoryInterface $warehouses)
    {
    }

    public function expiry(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $this->lots->authorize($actor);
        try {
            $report = $this->lots->expiryReport($actor, $request->query());
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $report = $this->lots->expiryReport($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return $this->render('expiry', $report + ['actor' => $actor, 'error' => $error, 'warehouses' => $this->warehouses->all()], $status);
    }

    public function expiryCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            return CsvResponse::download('expiry-report.csv', $this->lots->expiryCsv($actor, $request->query()));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $this->lots->authorize($actor);
        $query = is_string($request->query()['q'] ?? null) ? trim((string) $request->query()['q']) : '';
        try {
            $result = $this->lots->search($actor, $request->query());
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $result = $this->lots->search($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return $this->render('index', ['result' => $result, 'query' => $query, 'error' => $error], $status);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $trace = $this->lots->trace($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));

        return $this->render('show', $trace + ['error' => '']);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data, int $status = 200): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/lots/' . $view . '.php';
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $status);
    }
}
