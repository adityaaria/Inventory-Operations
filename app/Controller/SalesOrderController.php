<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\SalesOrderService;
use App\Support\OrderSearchCriteria;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class SalesOrderController
{
    private const INDEX_PATH = '/sales-orders';

    /**
     * @param array<int, Customer> $customers
     * @param array<int, Warehouse> $warehouses
     */
    public function __construct(
        private readonly SalesOrderService $salesOrders,
        private readonly SalesOrderRepositoryInterface $repository,
        private readonly ProductRepositoryInterface $products,
        private readonly array $customers,
        private readonly array $warehouses,
        private readonly AuthGuard $guard,
        private readonly ?\App\Service\OrderExceptionService $exceptions = null,
        private readonly ?\App\Repository\Contract\StockLedgerRepositoryInterface $ledger = null,
        private readonly ?\App\Service\ProductUnitCatalog $units = null,
        private readonly ?\App\Service\DeliveryNoteService $deliveryNotes = null,
        private readonly ?\App\Repository\Contract\PriceListRepositoryInterface $priceLists = null,
        private readonly ?\App\Repository\Contract\QuotationRepositoryInterface $quotations = null,
        private readonly ?\App\Service\InventoryValuationService $valuation = null,
    ) {
    }

    /** Units per product (D-16, ADR-022) for the order form and quantity display; base unit only without a catalog. */
    private function unitCatalog(): \App\Service\ProductUnitCatalog
    {
        return $this->units ?? new \App\Service\ProductUnitCatalog($this->products);
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $criteria = OrderSearchCriteria::fromArray($request->query());
        $createdBy = $actor->role() === User::ROLE_SALES ? $actor->userId() : null;

        return $this->render('sales-orders/index.php', [
            'result' => $this->repository->search($criteria, $createdBy),
            'criteria' => $criteria,
            'customers' => $this->customers,
            'warehouses' => $this->warehouses,
            'canCreate' => in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_SALES], true),
        ]);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $order = $this->findOrder((int) ($request->query()['id'] ?? 0));
        if ($actor->role() === User::ROLE_SALES && $order->createdBy() !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }

        return $this->renderShow($order, $actor);
    }

    public function create(): Response
    {
        $actor = $this->guard->requireAuth();
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_SALES], true)) {
            throw new HttpException(403, 'Forbidden');
        }

        return $this->render('sales-orders/create.php', [
            'customers' => $this->customers,
            'warehouses' => $this->warehouses,
            'products' => $this->activeProducts(),
            'error' => '',
            'maxDiscountPercent' => $this->salesOrders->salesMaxDiscountPercent(),
            'priceResolution' => $this->priceLists !== null,
        ]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();

        try {
            // Any client-sent order_number is ignored: the service assigns it (D-11, ADR-017).
            $this->salesOrders->createDraft(
                $actor,
                InputValidator::positiveInt('customer_id', $post['customer_id'] ?? ''),
                InputValidator::positiveInt('warehouse_id', $post['warehouse_id'] ?? ''),
                \App\Validation\OrderItemsInput::sales($post),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('sales-orders/create.php', [
                'old' => $post,
                'customers' => $this->customers,
                'warehouses' => $this->warehouses,
                'products' => $this->activeProducts(),
                'error' => $exception->getMessage(),
                'maxDiscountPercent' => $this->salesOrders->salesMaxDiscountPercent(),
                'priceResolution' => $this->priceLists !== null,
            ], 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function submit(Request $request): Response
    {
        $this->salesOrders->submit($this->guard->requireAuth(), InputValidator::positiveInt('id', $request->post()['id'] ?? ''));

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function approve(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        try {
            // Approval reserves the lines at the source warehouse (D-12, ADR-018); a shortage is a 422 on the detail page.
            $this->salesOrders->approve($actor, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($this->findOrder($id), $actor, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function cancel(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        try {
            $this->salesOrders->rejectOrCancel($actor, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($this->findOrder($id), $actor, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function issue(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        $order = $this->findOrder($id);
        try {
            $post = $request->post();
            // Per-line shipment quantities (D-14, ADR-020); a post without `quantities` issues every remaining unit.
            // Vehicle and driver go on the delivery note created with the shipment (D-19, ADR-025).
            $this->salesOrders->issue($actor, $id, \App\Service\OperationIdempotency::validateKey($post['operation_key'] ?? null), self::issueQuantities($post),
                ['vehicle' => $post['vehicle'] ?? null, 'driver' => $post['driver'] ?? null]);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($order, $actor, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => '/sales-orders/show?id=' . $id]);
    }

    /** Admin closes the unshipped remainder of a PartiallyFulfilled order with a reason (D-14, ADR-020). */
    public function closeRemaining(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        $reason = $request->post()['reason'] ?? null;
        try {
            $this->salesOrders->closeRemaining($actor, $id, $reason);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($this->findOrder($id), $actor, $exception->getMessage(), 422, [], ['open' => true, 'reason' => is_string($reason) ? $reason : '', 'error' => $exception->getMessage()]);
        }

        return new Response('', 302, ['Location' => '/sales-orders/show?id=' . $id]);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<int, int>|null item id => quantity, or null when the form sent no per-line quantities
     */
    private static function issueQuantities(array $post): ?array
    {
        if (!array_key_exists('quantities', $post)) {
            return null;
        }
        if (!is_array($post['quantities'])) {
            throw new \App\Exception\ValidationException('Issue quantities are invalid.');
        }
        $quantities = [];
        foreach ($post['quantities'] as $itemId => $value) {
            $item = InputValidator::positiveInt('item', (string) $itemId);
            $quantities[$item] = $value === '' || $value === null ? 0 : \App\Service\BusinessOperationInput::quantity($value, true);
        }

        return $quantities;
    }

    public function reject(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$id=InputValidator::positiveInt('id',$request->post()['id']??'');
        if($this->exceptions===null) { throw new \LogicException('Order exceptions service is required.'); }
        $reason=$request->post()['reason']??null;
        try {
            $this->exceptions->reject($actor,$id,\App\Service\BusinessOperationInput::reason($reason));
        } catch (InvalidArgumentException $exception) {
            // Reopen the reason dialog with the typed text; if the order is no longer pending, show the message on the page.
            $order = $this->findOrder($id);
            if ($order->status() !== SalesOrder::STATUS_PENDING_APPROVAL) {
                return $this->renderShow($order, $actor, $exception->getMessage(), 422);
            }
            return $this->renderShow($order, $actor, '', 422, ['open' => true, 'reason' => is_string($reason) ? $reason : '', 'error' => $exception->getMessage()]);
        }
        return new Response('',302,['Location'=>'/sales-orders/show?id='.$id]);
    }

    private function findOrder(int $id): SalesOrder
    {
        return $this->repository->findById($id) ?? throw new HttpException(404, 'Sales order not found.');
    }

    /**
     * @param array{open?: bool, reason?: string, error?: string} $rejectDialog
     * @param array{open?: bool, reason?: string, error?: string} $closeDialog
     */
    private function renderShow(SalesOrder $order, \App\Security\AuthContext $actor, string $error = '', int $status = 200, array $rejectDialog = [], array $closeDialog = []): Response
    {
        return $this->render('sales-orders/show.php', [
            'rejectDialog' => $rejectDialog,
            'closeDialog' => $closeDialog,
            'closure' => $this->salesOrders->remainderClosure($order->id()),
            'order' => $order,
            'availability' => $this->salesOrders->sourceAvailability($actor, $order),
            'customers' => $this->customers,
            'warehouses' => $this->warehouses,
            'canSubmit' => $order->status() === SalesOrder::STATUS_DRAFT
                && ($actor->role() === User::ROLE_ADMIN || $order->createdBy() === $actor->userId()),
            'canApproveOrCancel' => $actor->role() === User::ROLE_ADMIN,
            'canIssue' => in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true),
            'error' => $error,
        ], $status);
    }

    /** @return list<Product> */
    private function activeProducts(): array
    {
        return $this->products->active();
    }

    /**
     * Readable "SKU — name" per ordered product for detail pages; inactive products stay visible as history.
     *
     * @param list<\App\Entity\SalesOrderItem> $items
     * @return array<int, string>
     */
    private function productLabels(array $items): array
    {
        $labels = [];
        foreach ($items as $item) {
            $product = $this->products->findById($item->productId());
            $labels[$item->productId()] = $product === null ? 'Product #' . $item->productId() : $product->sku() . ' — ' . $product->name();
        }

        return $labels;
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = [], int $statusCode = 200): Response
    {
        if(isset($data['order']) && $data['order'] instanceof SalesOrder){$data['rejection']=$this->exceptions?->rejection($data['order']->id());$data['movements']=$this->ledger?->forReference('SO',$data['order']->id())??[];$data['productLabels']=$this->productLabels($data['order']->items());}
        if (isset($data['order']) && $data['order'] instanceof SalesOrder) {
            // D-19 / ADR-025: delivery notes of the shipments (with their invoices), price sources and the source quotation.
            $data['deliveryNotes'] = $this->deliveryNotes === null ? [] : $this->deliveryNotes->forOrder($this->guard->requireAuth(), $data['order']);
            $data['priceListNames'] = [];
            foreach ($this->priceLists?->all() ?? [] as $list) {
                $data['priceListNames'][$list['id']] = $list['name'];
            }
            $data['quotation'] = $data['order']->quotationId() === null ? null : $this->quotations?->find($data['order']->quotationId());
            // D-20 / ADR-026: COGS of the shipped lots and margin of the invoiced shipments, Admin only.
            $viewer = $this->guard->requireAuth();
            $data['costing'] = $viewer->role() === User::ROLE_ADMIN ? $this->valuation?->orderCosting($viewer, $data['order']->id()) : null;
        }
        if (isset($data['order']) && is_object($data['order']) && method_exists($data['order'], 'items')) {
            $data['unitSets'] = $this->unitCatalog()->forProductIds(array_map(static fn ($item): int => $item->productId(), $data['order']->items()));
        }
        if (isset($data['products']) && is_array($data['products'])) {
            $data['unitSets'] = $this->unitCatalog()->forProducts($data['products']);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $statusCode);
    }
}
