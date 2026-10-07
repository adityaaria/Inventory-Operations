<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\PurchaseOrderRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\PurchaseOrderService;
use App\Support\OrderSearchCriteria;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class PurchaseOrderController
{
    /**
     * @param array<int, Supplier> $suppliers
     * @param array<int, Warehouse> $warehouses
     */
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly PurchaseOrderRepositoryInterface $repository,
        private readonly ProductRepositoryInterface $products,
        private readonly array $suppliers,
        private readonly array $warehouses,
        private readonly AuthGuard $guard,
        private readonly ?\App\Service\OrderExceptionService $exceptions = null,
        private readonly ?\App\Repository\Contract\StockLedgerRepositoryInterface $ledger = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $criteria = OrderSearchCriteria::fromArray($request->query());

        return $this->render('purchase-orders/index.php', [
            'result' => $this->repository->search($criteria),
            'criteria' => $criteria,
            'suppliers' => $this->suppliers,
            'warehouses' => $this->warehouses,
            'canCreate' => in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true),
        ]);
    }

    public function show(Request $request): Response
    {
        $this->guard->requireAuth();
        $order = $this->findOrder((int) ($request->query()['id'] ?? 0));

        return $this->render('purchase-orders/show.php', [
            'order' => $order,
            'suppliers' => $this->suppliers,
            'warehouses' => $this->warehouses,
            'canOrderOrCancel' => $this->guard->requireAuth()->role() === User::ROLE_ADMIN,
            'canReceive' => in_array($this->guard->requireAuth()->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)) {
            throw new HttpException(403, 'Forbidden');
        }

        $old = array_intersect_key(array_filter($request->query(), 'is_string'), array_flip(['warehouse_id','product_id','quantity']));
        $error = '';
        try {
            $old = $this->replenishmentPrefill($request->query()) + $old;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }

        return $this->render('purchase-orders/create.php', [
            'suppliers' => $this->suppliers,
            'warehouses' => $this->warehouses,
            'products' => $this->activeProducts(),
            'error' => $error,
            'old' => $old,
        ], $error === '' ? 200 : 422);
    }

    /**
     * Turns selected replenishment recommendations (`pick[]=productId:quantity`) into reviewable lines.
     * Prefill only: supplier, quantities and prices are still reviewed and fully revalidated on submit.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function replenishmentPrefill(array $query): array
    {
        if (!array_key_exists('pick', $query)) {
            return [];
        }
        $picks = $query['pick'];
        if (!is_array($picks) || $picks === [] || count($picks) > \App\Validation\OrderItemsInput::MAX_ITEMS) {
            throw new InvalidArgumentException('Select between 1 and ' . \App\Validation\OrderItemsInput::MAX_ITEMS . ' recommendations.');
        }
        $lines = [];
        foreach ($picks as $pick) {
            if (!is_string($pick) || preg_match('/^([1-9][0-9]{0,9}):([1-9][0-9]{0,9})$/', $pick, $match) !== 1 || isset($lines[$match[1]])) {
                throw new InvalidArgumentException('Invalid replenishment selection.');
            }
            $lines[$match[1]] = ['product_id' => $match[1], 'quantity' => $match[2]];
        }
        $first = array_shift($lines);
        $items = [];
        foreach (array_values($lines) as $index => $line) {
            $items[$index + 1] = $line;
        }

        return ['product_id' => $first['product_id'], 'quantity' => $first['quantity'], 'items' => $items];
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();

        try {
            $this->purchaseOrders->createDraft(
                $actor,
                InputValidator::optionalString('order_number', $post['order_number'] ?? '', 255),
                InputValidator::positiveInt('supplier_id', $post['supplier_id'] ?? ''),
                InputValidator::positiveInt('warehouse_id', $post['warehouse_id'] ?? ''),
                \App\Validation\OrderItemsInput::purchase($post),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('purchase-orders/create.php', [
                'old' => $post,
                'suppliers' => $this->suppliers,
                'warehouses' => $this->warehouses,
                'products' => $this->activeProducts(),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/purchase-orders']);
    }

    public function order(Request $request): Response
    {
        $this->purchaseOrders->markOrdered($this->guard->requireAuth(), InputValidator::positiveInt('id', $request->post()['id'] ?? ''));

        return new Response('', 302, ['Location' => '/purchase-orders']);
    }

    public function receive(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');
        $order = $this->findOrder($id);

        try {
            $this->purchaseOrders->receive($actor, $id, [
                InputValidator::positiveInt('item_id', $post['item_id'] ?? '') => InputValidator::positiveInt('quantity', $post['quantity'] ?? ''),
            ], \App\Service\OperationIdempotency::validateKey($post['operation_key'] ?? null));
        } catch (InvalidArgumentException $exception) {
            return $this->render('purchase-orders/show.php', [
                'order' => $order,
                'suppliers' => $this->suppliers,
                'warehouses' => $this->warehouses,
                'canOrderOrCancel' => $actor->role() === User::ROLE_ADMIN,
                'canReceive' => in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/purchase-orders/show?id=' . $id]);
    }

    public function cancel(Request $request): Response
    {
        $this->purchaseOrders->cancel($this->guard->requireAuth(), InputValidator::positiveInt('id', $request->post()['id'] ?? ''));

        return new Response('', 302, ['Location' => '/purchase-orders']);
    }

    public function closeRemainder(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$id=InputValidator::positiveInt('id',$request->post()['id']??'');
        if($this->exceptions===null) { throw new \LogicException('Order exceptions service is required.'); }
        $this->exceptions->close($actor,$id,\App\Service\BusinessOperationInput::reason($request->post()['reason']??null));
        return new Response('',302,['Location'=>'/purchase-orders/show?id='.$id]);
    }

    private function findOrder(int $id): PurchaseOrder
    {
        return $this->repository->findById($id) ?? throw new HttpException(404, 'Purchase order not found.');
    }

    /** @return list<Product> */
    private function activeProducts(): array
    {
        return $this->products->active();
    }

    /**
     * Readable "SKU — name" per ordered product for detail pages; inactive products stay visible as history.
     *
     * @param list<\App\Entity\PurchaseOrderItem> $items
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
        if(isset($data['order']) && $data['order'] instanceof PurchaseOrder){$data['closure']=$this->exceptions?->closure($data['order']->id());$data['movements']=$this->ledger?->forReference('PO',$data['order']->id())??[];$data['productLabels']=$this->productLabels($data['order']->items());}
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $statusCode);
    }
}
