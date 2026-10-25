<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\InventoryValuationService;
use App\Support\CsvResponse;
use InvalidArgumentException;

/**
 * Inventory valuation FIFO per batch, period reconciliation, gross margin and losses (D-20, ADR-026). HTTP only:
 * InventoryValuationService authorizes (Admin only, 403 otherwise) and validates.
 */
final class ValuationController
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
        private readonly AuthGuard $guard,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly CategoryRepositoryInterface $categories,
        private readonly CustomerRepositoryInterface $customers,
    ) {
    }

    public function valuation(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        [$report, $error, $status] = $this->attempt(fn (): array => $this->valuation->valuation($actor, $request->query()), fn (): array => $this->valuation->valuation($actor, []));

        return View::render('valuation/index.php', ['report' => $report, 'error' => $error, 'query' => $request->query(), 'warehouses' => $this->warehouses->all(), 'categories' => $this->categories->all()], $status);
    }

    public function valuationCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->csv('inventory-valuation.csv', fn (): string => $this->valuation->valuationCsv($actor, $request->query()));
    }

    public function reconciliation(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        [$report, $error, $status] = $this->attempt(fn (): array => $this->valuation->reconciliation($actor, $request->query()), fn (): ?array => $this->fallback(fn (): array => $this->valuation->reconciliation($actor, [])));

        return View::render('valuation/reconciliation.php', ['report' => $report, 'error' => $error, 'query' => $request->query()], $status);
    }

    public function reconciliationCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->csv('valuation-reconciliation.csv', fn (): string => $this->valuation->reconciliationCsv($actor, $request->query()));
    }

    public function margin(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        [$report, $error, $status] = $this->attempt(fn (): array => $this->valuation->margin($actor, $request->query()), fn (): array => $this->valuation->margin($actor, []));

        return View::render('valuation/margin.php', ['report' => $report, 'error' => $error, 'query' => $request->query(), 'customers' => $this->customers->all()], $status);
    }

    public function marginCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->csv('gross-margin.csv', fn (): string => $this->valuation->marginCsv($actor, $request->query()));
    }

    public function losses(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        [$report, $error, $status] = $this->attempt(fn (): array => $this->valuation->losses($actor, $request->query()), fn (): array => $this->valuation->losses($actor, []));

        return View::render('valuation/losses.php', ['report' => $report, 'error' => $error, 'query' => $request->query()], $status);
    }

    public function lossesCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->csv('inventory-losses.csv', fn (): string => $this->valuation->lossesCsv($actor, $request->query()));
    }

    /**
     * Runs the report; on invalid input re-runs it with defaults and returns the message with 422 (403 passes through).
     *
     * @param callable(): array<string, mixed> $run
     * @param callable(): (array<string, mixed>|null) $defaults
     * @return array{0: array<string, mixed>|null, 1: string, 2: int}
     */
    private function attempt(callable $run, callable $defaults): array
    {
        try {
            return [$run(), '', 200];
        } catch (InvalidArgumentException $exception) {
            return [$defaults(), $exception->getMessage(), 422];
        }
    }

    /**
     * @param callable(): array<string, mixed> $run
     * @return array<string, mixed>|null
     */
    private function fallback(callable $run): ?array
    {
        try {
            return $run();
        } catch (InvalidArgumentException) {
            return null; // the default period itself may end before the valuation start
        }
    }

    /** @param callable(): string $build */
    private function csv(string $filename, callable $build): Response
    {
        try {
            return CsvResponse::download($filename, $build());
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }
}
