<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Service\ProductUnitCatalog;
use App\Service\QuotationService;
use App\Validation\InputValidator;
use App\Validation\OrderItemsInput;
use InvalidArgumentException;

/** Quotations (D-19, ADR-025). HTTP only; QuotationService authorizes (Sales own, Admin all) and validates. */
final class QuotationController
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly AuthGuard $guard,
        private readonly CustomerRepositoryInterface $customers,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductUnitCatalog $units,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            $result = $this->quotations->search($actor, $request->query());
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $result = $this->quotations->search($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return View::render('quotations/index.php', ['result' => $result, 'query' => $request->query(), 'error' => $error, 'today' => $this->quotations->today()], $status);
    }

    public function create(): Response
    {
        $actor = $this->guard->requireAuth();
        $this->quotations->assertCanCreate($actor);

        return $this->renderForm([], '');
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();
        try {
            $id = $this->quotations->create(
                $actor,
                InputValidator::positiveInt('customer_id', $post['customer_id'] ?? ''),
                InputValidator::positiveInt('warehouse_id', $post['warehouse_id'] ?? ''),
                $post['valid_until'] ?? null,
                $post['notes'] ?? null,
                OrderItemsInput::sales($post),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->renderForm($post, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => '/quotations/show?id=' . $id]);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->renderShow($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));
    }

    public function print(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $quotation = $this->quotations->show($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));

        return View::render('quotations/print.php', ['quotation' => $quotation, 'customer' => $this->customers->findById((int) $quotation['customer_id']), 'unitSets' => $this->unitSets($quotation)]);
    }

    public function send(Request $request): Response
    {
        return $this->act($request, fn (AuthContext $actor, int $id) => $this->quotations->send($actor, $id));
    }

    public function accept(Request $request): Response
    {
        return $this->act($request, fn (AuthContext $actor, int $id) => $this->quotations->accept($actor, $id));
    }

    public function reject(Request $request): Response
    {
        $reason = $request->post()['reason'] ?? null;

        return $this->act($request, fn (AuthContext $actor, int $id) => $this->quotations->reject($actor, $id, $reason), ['reason' => is_string($reason) ? $reason : '']);
    }

    public function cancel(Request $request): Response
    {
        return $this->act($request, fn (AuthContext $actor, int $id) => $this->quotations->cancel($actor, $id));
    }

    public function convert(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        try {
            $orderId = $this->quotations->convert($actor, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($actor, $id, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => '/sales-orders/show?id=' . $orderId]);
    }

    /** @param callable(AuthContext, int): mixed $change @param array{reason?: string} $dialog */
    private function act(Request $request, callable $change, array $dialog = []): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        try {
            $change($actor, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($actor, $id, $exception->getMessage(), 422, $dialog === [] ? [] : $dialog + ['open' => true, 'error' => $exception->getMessage()]);
        }

        return new Response('', 302, ['Location' => '/quotations/show?id=' . $id]);
    }

    /** @param array<string, mixed> $rejectDialog */
    private function renderShow(AuthContext $actor, int $id, string $error = '', int $status = 200, array $rejectDialog = []): Response
    {
        $quotation = $this->quotations->show($actor, $id);

        return View::render('quotations/show.php', ['quotation' => $quotation, 'error' => $error, 'rejectDialog' => $rejectDialog, 'unitSets' => $this->unitSets($quotation)], $status);
    }

    /** @param array<string, mixed> $old */
    private function renderForm(array $old, string $error, int $status = 200): Response
    {
        $products = $this->products->active();

        return View::render('quotations/create.php', [
            'old' => $old,
            'error' => $error,
            'customers' => $this->customers->all(),
            'warehouses' => $this->warehouses->all(),
            'products' => $products,
            'unitSets' => $this->units->forProducts($products),
            'validUntil' => $this->quotations->defaultValidUntil(),
            'maxDiscountPercent' => $this->quotations->salesMaxDiscountPercent(),
        ], $status);
    }

    /** @param array<string, mixed> $quotation @return array<int, \App\Support\UnitSet> */
    private function unitSets(array $quotation): array
    {
        return $this->units->forProductIds(array_map(static fn (array $line): int => (int) $line['product_id'], $quotation['lines']));
    }
}
