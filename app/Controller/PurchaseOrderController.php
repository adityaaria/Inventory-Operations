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

        return $this->render('purchase-orders/create.php', [
            'suppliers' => $this->suppliers,
            'warehouses' => $this->warehouses,
            'products' => $this->activeProducts(),
            'error' => '',
        ]);
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
                [[
                    'product_id' => InputValidator::positiveInt('product_id', $post['product_id'] ?? ''),
                    'quantity' => InputValidator::positiveInt('quantity', $post['quantity'] ?? ''),
                    'purchase_price' => InputValidator::nonNegativeMoney('purchase_price', $post['purchase_price'] ?? ''),
                ]],
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
            ]);
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

    private function findOrder(int $id): PurchaseOrder
    {
        return $this->repository->findById($id) ?? throw new HttpException(404, 'Purchase order not found.');
    }

    /** @return list<Product> */
    private function activeProducts(): array
    {
        return $this->products->active();
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = [], int $statusCode = 200): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $statusCode);
    }
}
