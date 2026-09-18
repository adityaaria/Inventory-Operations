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
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;

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
        $this->guard->requireAuth();
        $criteria = OrderSearchCriteria::fromArray($request->query());

        return $this->render('purchase-orders/index.php', [
            'result' => $this->repository->search($criteria),
            'criteria' => $criteria,
            'suppliers' => $this->suppliers,
            'warehouses' => $this->warehouses,
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
                (string) ($post['order_number'] ?? ''),
                (int) ($post['supplier_id'] ?? 0),
                (int) ($post['warehouse_id'] ?? 0),
                [[
                    'product_id' => (int) ($post['product_id'] ?? 0),
                    'quantity' => (int) ($post['quantity'] ?? 0),
                    'purchase_price' => (float) ($post['purchase_price'] ?? 0),
                ]],
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('purchase-orders/create.php', [
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
        $this->purchaseOrders->markOrdered($this->guard->requireAuth(), (int) ($request->post()['id'] ?? 0));

        return new Response('', 302, ['Location' => '/purchase-orders']);
    }

    public function receive(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();
        $id = (int) ($post['id'] ?? 0);
        $order = $this->findOrder($id);

        try {
            $this->purchaseOrders->receive($actor, $id, [
                (int) ($post['item_id'] ?? 0) => (int) ($post['quantity'] ?? 0),
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
        $this->purchaseOrders->cancel($this->guard->requireAuth(), (int) ($request->post()['id'] ?? 0));

        return new Response('', 302, ['Location' => '/purchase-orders']);
    }

    private function findOrder(int $id): PurchaseOrder
    {
        return $this->repository->findById($id) ?? throw new HttpException(404, 'Purchase order not found.');
    }

    /** @return list<Product> */
    private function activeProducts(): array
    {
        return $this->products->search(ProductSearchCriteria::fromArray(['per_page' => '100']))->items();
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
