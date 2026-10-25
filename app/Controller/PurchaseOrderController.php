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
    private const INDEX_PATH = '/purchase-orders';

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
        private readonly ?\App\Service\ProductUnitCatalog $units = null,
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

        $old = array_intersect_key(array_filter($request->query(), 'is_string'), array_flip(['warehouse_id','product_id','quantity','unit_code']));
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
     * Turns selected replenishment recommendations (`pick[]=productId:quantity[:unit]`) into reviewable lines. Since
     * D-16/ADR-022 the quantity is in whole purchase-default units (the unit after the second colon; none = base unit).
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
            if (!is_string($pick) || preg_match('/^([1-9]\d{0,9}):([1-9]\d{0,9})(?::([^:]{1,30}))?$/uD', $pick, $match) !== 1 || isset($lines[$match[1]])) {
                throw new InvalidArgumentException('Invalid replenishment selection.');
            }
            $lines[$match[1]] = ['product_id' => $match[1], 'quantity' => $match[2]] + (isset($match[3]) ? ['unit_code' => $match[3]] : []);
        }
        $first = array_shift($lines);
        $items = [];
        foreach (array_values($lines) as $index => $line) {
            $items[$index + 1] = $line;
        }

        return array_intersect_key($first, array_flip(['product_id', 'quantity', 'unit_code'])) + ['items' => $items];
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();

        try {
            // Any client-sent order_number is ignored: the service assigns it (D-11, ADR-017).
            $this->purchaseOrders->createDraft(
                $actor,
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

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function order(Request $request): Response
    {
        $this->purchaseOrders->markOrdered($this->guard->requireAuth(), InputValidator::positiveInt('id', $request->post()['id'] ?? ''));

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function receive(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');
        $order = $this->findOrder($id);

        try {
            $itemId = InputValidator::positiveInt('item_id', $post['item_id'] ?? '');
            $quantity = InputValidator::positiveInt('quantity', $post['quantity'] ?? '');
            // Lot splits (D-17, ADR-023): lot_code[], expiry_date[], lot_quantity[] in the line unit.
            $splits = \App\Validation\LotInput::splitsFromForm($post['lot_code'] ?? [], $post['expiry_date'] ?? [], $post['lot_quantity'] ?? [], $quantity);
            $this->purchaseOrders->receive($actor, $id, [$itemId => $quantity], \App\Service\OperationIdempotency::validateKey($post['operation_key'] ?? null), $splits === [] ? null : [$itemId => $splits]);
        } catch (InvalidArgumentException $exception) {
            return $this->render('purchase-orders/show.php', [
                'order' => $order,
                'suppliers' => $this->suppliers,
                'warehouses' => $this->warehouses,
                'canOrderOrCancel' => $actor->role() === User::ROLE_ADMIN,
                'canReceive' => in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true),
                'error' => $exception->getMessage(),
                'receiptInput' => $post,
            ], 422);
        }

        return new Response('', 302, ['Location' => '/purchase-orders/show?id=' . $id]);
    }

    public function cancel(Request $request): Response
    {
        $this->purchaseOrders->cancel($this->guard->requireAuth(), InputValidator::positiveInt('id', $request->post()['id'] ?? ''));

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
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
            $this->tracked[$item->productId()] = $product !== null && $product->tracksExpiry();
        }

        return $labels;
    }

    /** @var array<int, bool> product id => tracks expiry (D-17), filled with the labels */
    private array $tracked = [];

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = [], int $statusCode = 200): Response
    {
        if(isset($data['order']) && $data['order'] instanceof PurchaseOrder){$data['closure']=$this->exceptions?->closure($data['order']->id());$data['movements']=$this->ledger?->forReference('PO',$data['order']->id())??[];$data['productLabels']=$this->productLabels($data['order']->items());$data['trackedProducts']=$this->tracked;}
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
