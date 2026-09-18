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
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;

final class SalesOrderController
{
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
    ) {
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

    public function create(Request $request): Response
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
        ]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();

        try {
            $this->salesOrders->createDraft(
                $actor,
                (string) ($post['order_number'] ?? ''),
                (int) ($post['customer_id'] ?? 0),
                (int) ($post['warehouse_id'] ?? 0),
                [[
                    'product_id' => (int) ($post['product_id'] ?? 0),
                    'quantity' => (int) ($post['quantity'] ?? 0),
                    'selling_price' => (float) ($post['selling_price'] ?? 0),
                ]],
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('sales-orders/create.php', [
                'customers' => $this->customers,
                'warehouses' => $this->warehouses,
                'products' => $this->activeProducts(),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/sales-orders']);
    }

    public function submit(Request $request): Response
    {
        $this->salesOrders->submit($this->guard->requireAuth(), (int) ($request->post()['id'] ?? 0));

        return new Response('', 302, ['Location' => '/sales-orders']);
    }

    public function approve(Request $request): Response
    {
        $this->salesOrders->approve($this->guard->requireAuth(), (int) ($request->post()['id'] ?? 0));

        return new Response('', 302, ['Location' => '/sales-orders']);
    }

    public function cancel(Request $request): Response
    {
        $this->salesOrders->rejectOrCancel($this->guard->requireAuth(), (int) ($request->post()['id'] ?? 0));

        return new Response('', 302, ['Location' => '/sales-orders']);
    }

    public function issue(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = (int) ($request->post()['id'] ?? 0);
        $order = $this->findOrder($id);
        try {
            $this->salesOrders->issue($actor, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($order, $actor, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => '/sales-orders/show?id=' . $id]);
    }

    private function findOrder(int $id): SalesOrder
    {
        return $this->repository->findById($id) ?? throw new HttpException(404, 'Sales order not found.');
    }

    private function renderShow(SalesOrder $order, \App\Security\AuthContext $actor, string $error = '', int $status = 200): Response
    {
        return $this->render('sales-orders/show.php', [
            'order' => $order,
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
