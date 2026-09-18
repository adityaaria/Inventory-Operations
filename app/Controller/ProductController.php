<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\ProductService;
use App\Support\CsvImport;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;

final class ProductController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductRepositoryInterface $repository,
        private readonly CategoryRepositoryInterface $categories,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $criteria = ProductSearchCriteria::fromArray($request->query());
        $result = $this->products->search($actor, $criteria);
        $categories = $this->categories->active();

        return $this->render('products/index.php', [
            'result' => $result,
            'criteria' => $criteria,
            'categories' => $categories,
            'stocksByProduct' => $this->stocksByProduct($result->items()),
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'error' => '',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('products/create.php', [
            'categories' => $this->categories->active(),
            'error' => '',
        ]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->products->create(
                $actor,
                (string) ($post['sku'] ?? ''),
                (string) ($post['name'] ?? ''),
                (string) ($post['unit'] ?? ''),
                (float) ($post['purchase_price'] ?? 0),
                (float) ($post['selling_price'] ?? 0),
                (int) ($post['reorder_point'] ?? 0),
                (int) ($post['category_id'] ?? 0),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('products/create.php', [
                'categories' => $this->categories->active(),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/products']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            foreach (CsvImport::rowsFromRequest($request) as $row) {
                $this->products->create(
                    $actor,
                    $row['sku'] ?? '',
                    $row['name'] ?? '',
                    $row['unit'] ?? '',
                    (float) ($row['purchase_price'] ?? 0),
                    (float) ($row['selling_price'] ?? 0),
                    (int) ($row['reorder_point'] ?? 0),
                    (int) ($row['category_id'] ?? 0),
                );
            }
        } catch (\Throwable $exception) {
            $criteria = ProductSearchCriteria::fromArray([]);
            $result = $this->products->search($actor, $criteria);

            return $this->render('products/index.php', [
                'result' => $result,
                'criteria' => $criteria,
                'categories' => $this->categories->active(),
                'stocksByProduct' => $this->stocksByProduct($result->items()),
                'canWrite' => true,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/products']);
    }

    public function edit(Request $request): Response
    {
        $this->guard->requireUserManagement();
        $product = $this->repository->findById((int) ($request->query()['id'] ?? 0));
        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        return $this->render('products/edit.php', [
            'product' => $product,
            'categories' => $this->categories->active(),
            'error' => '',
        ]);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();
        $id = (int) ($post['id'] ?? 0);

        try {
            $this->products->update(
                $actor,
                $id,
                (string) ($post['sku'] ?? ''),
                (string) ($post['name'] ?? ''),
                (string) ($post['unit'] ?? ''),
                (float) ($post['purchase_price'] ?? 0),
                (float) ($post['selling_price'] ?? 0),
                (int) ($post['reorder_point'] ?? 0),
                (int) ($post['category_id'] ?? 0),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('products/edit.php', [
                'product' => $this->repository->findById($id),
                'categories' => $this->categories->active(),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/products']);
    }

    public function activate(Request $request): Response
    {
        $this->products->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), true);

        return new Response('', 302, ['Location' => '/products']);
    }

    public function deactivate(Request $request): Response
    {
        $this->products->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), false);

        return new Response('', 302, ['Location' => '/products']);
    }

    /**
     * @param list<Product> $products
     * @return array<int, list<\App\Entity\ProductStock>>
     */
    private function stocksByProduct(array $products): array
    {
        $stocks = [];
        foreach ($products as $product) {
            $stocks[$product->id()] = $this->repository->stocksForProduct($product->id());
        }

        return $stocks;
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = [], int $status = 200): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $status);
    }
}
