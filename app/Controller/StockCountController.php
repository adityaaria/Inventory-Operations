<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\StockCountService;
use App\Support\CsvResponse;
use App\Validation\InputValidator;
use InvalidArgumentException;

/** Stock count sessions and their report (D-18, ADR-024). HTTP only; StockCountService authorizes every action. */
final class StockCountController
{
    private const SHOW = '/stock-counts/show?id=';

    public function __construct(
        private readonly StockCountService $counts,
        private readonly AuthGuard $guard,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $this->counts->authorize($actor);
        $error = '';
        $status = 200;
        try {
            $result = $this->counts->search($actor, $request->query());
        } catch (InvalidArgumentException $exception) {
            $result = $this->counts->search($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return View::render('stock-counts/index.php', [
            'result' => $result,
            'filters' => ['status' => (string) ($request->query()['status'] ?? ''), 'warehouse_id' => (string) ($request->query()['warehouse_id'] ?? ''), 'mine' => (string) ($request->query()['mine'] ?? '')],
            'warehouses' => $this->warehouses->all(),
            'canCreate' => $actor->role() === 'Admin',
            'error' => $error,
        ], $status);
    }

    public function create(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        if ($actor->role() !== 'Admin') {
            throw new HttpException(403, 'Forbidden');
        }

        return $this->form([], '');
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            $id = $this->counts->create($actor, $request->post());
        } catch (InvalidArgumentException $exception) {
            return $this->form($request->post(), $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => self::SHOW . $id]);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->detail($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''), '');
    }

    public function start(Request $request): Response
    {
        return $this->action($request, fn ($actor, int $id) => $this->counts->start($actor, $id));
    }

    public function count(Request $request): Response
    {
        $counts = $request->post()['counts'] ?? [];

        return $this->action($request, fn ($actor, int $id) => $this->counts->record($actor, $id, is_array($counts) ? $counts : []), '#count-sheet');
    }

    public function foundLot(Request $request): Response
    {
        return $this->action($request, fn ($actor, int $id) => $this->counts->addFoundLot($actor, $id, $request->post()), '#count-sheet');
    }

    public function submit(Request $request): Response
    {
        return $this->action($request, fn ($actor, int $id) => $this->counts->submit($actor, $id));
    }

    public function recount(Request $request): Response
    {
        $post = $request->post();

        return $this->action($request, fn ($actor, int $id) => $this->counts->requestRecount($actor, $id, $post['line_ids'] ?? [], $post['reason'] ?? null, $post['counter_ids'] ?? []));
    }

    public function approve(Request $request): Response
    {
        return $this->action($request, fn ($actor, int $id) => $this->counts->approve($actor, $id, $request->post()['note'] ?? null));
    }

    public function post(Request $request): Response
    {
        return $this->action($request, fn ($actor, int $id) => $this->counts->post($actor, $id));
    }

    public function cancel(Request $request): Response
    {
        return $this->action($request, fn ($actor, int $id) => $this->counts->cancel($actor, $id, $request->post()['reason'] ?? null));
    }

    public function report(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $this->counts->authorize($actor);
        $error = '';
        $status = 200;
        try {
            $report = $this->counts->report($actor, $request->query());
        } catch (InvalidArgumentException $exception) {
            $report = $this->counts->report($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return View::render('stock-counts/report.php', $report + ['warehouses' => $this->warehouses->all(), 'error' => $error], $status);
    }

    public function reportCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            return CsvResponse::download('stock-count-variances.csv', $this->counts->reportCsv($actor, $request->query()));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    /** POST actions: 302 back to the count; 422/409 re-render the count with the message (403 stays an error page). */
    private function action(Request $request, callable $work, string $anchor = ''): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        try {
            $work($actor, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->detail($actor, $id, $exception->getMessage(), 422, $request->post());
        } catch (HttpException $exception) {
            if ($exception->statusCode() !== 409) {
                throw $exception;
            }

            return $this->detail($actor, $id, $exception->getMessage(), 409, $request->post());
        }

        return new Response('', 302, ['Location' => self::SHOW . $id . $anchor]);
    }

    /** @param array<string, mixed> $old */
    private function detail(\App\Security\AuthContext $actor, int $id, string $error, int $status = 200, array $old = []): Response
    {
        return View::render('stock-counts/show.php', $this->counts->show($actor, $id) + ['error' => $error, 'old' => $old], $status);
    }

    /** @param array<string, mixed> $old */
    private function form(array $old, string $error, int $status = 200): Response
    {
        return View::render('stock-counts/create.php', [
            'old' => $old,
            'error' => $error,
            'warehouses' => array_values(array_filter($this->warehouses->all(), static fn ($warehouse): bool => $warehouse->isActive())),
            'categories' => array_values(array_filter($this->categories->all(), static fn ($category): bool => $category->isActive())),
            'products' => $this->products->active(),
            'counters' => $this->counts->eligibleCounters(),
        ], $status);
    }
}
