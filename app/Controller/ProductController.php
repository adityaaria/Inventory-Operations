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
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class ProductController
{
    private const INDEX_PATH = '/products';

    public function __construct(
        private readonly ProductService $products,
        private readonly ProductRepositoryInterface $repository,
        private readonly CategoryRepositoryInterface $categories,
        private readonly AuthGuard $guard,
        private readonly ?\App\Service\CsvImportService $imports = null,
        private readonly ?\App\Service\ReorderPointService $reorderPoints = null,
        private readonly ?\App\Service\ProductUnitService $unitService = null,
        private readonly ?\App\Service\ProductUnitCatalog $units = null,
        private readonly ?\App\Service\LotService $lots = null,
    ) {
    }

    /** Admin adds a unit of measure to a product (D-16, ADR-022); the service authorizes (403) and validates (422). */
    public function addUnit(Request $request): Response
    {
        return $this->changeUnit($request, false);
    }

    /** Admin edits, deactivates or reactivates a unit, sets defaults, barcode and per-unit prices (D-16, ADR-022). */
    public function updateUnit(Request $request): Response
    {
        return $this->changeUnit($request, true);
    }

    private function changeUnit(Request $request, bool $update): Response
    {
        $actor = $this->guard->requireAuth();
        if ($this->unitService === null) { throw new \LogicException('Product unit service is not configured.'); }
        $this->unitService->assertCanManage($actor); // 403 before any input parsing for non-Admin roles
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');
        $unitId = $update ? InputValidator::positiveInt('unit_id', $post['unit_id'] ?? '') : 0;
        try {
            if ($update) {
                $this->unitService->update($actor, $id, $unitId, $post);
            } else {
                $this->unitService->add($actor, $id, $post);
            }
        } catch (InvalidArgumentException $exception) {
            $product = $this->products->detail($actor, $id);

            return $this->renderShow($product, true, '', 0, '', 422, ['error' => $exception->getMessage(), 'unit_id' => $unitId, 'input' => array_filter($post, 'is_string')]);
        }
        $this->units?->forget($id);

        return new Response('', 302, ['Location' => '/products/show?id=' . $id]);
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

    public function create(): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('products/create.php', [
            'categories' => $this->categories->active(),
            'error' => '',
        ]);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $product = $this->products->detail($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));
        return $this->renderShow($product, $actor->role() === \App\Entity\User::ROLE_ADMIN);
    }

    /** Sets or clears one warehouse's reorder point override; the service enforces Admin-only access (403). */
    public function reorderPoint(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        if ($this->reorderPoints === null) { throw new \LogicException('Reorder point service is not configured.'); }
        $this->reorderPoints->assertCanManage($actor); // 403 before any input parsing for non-Admin roles
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');
        $warehouseId = InputValidator::positiveInt('warehouse_id', $post['warehouse_id'] ?? '');
        try {
            $override = \App\Support\ReorderThreshold::parseOverride($post['reorder_point_override'] ?? '');
            $this->reorderPoints->setOverride($actor, $id, $warehouseId, $override);
        } catch (InvalidArgumentException $exception) {
            $product = $this->products->detail($actor, $id);

            return $this->renderShow($product, true, $exception->getMessage(), $warehouseId, $post['reorder_point_override'] ?? '', 422);
        }

        return new Response('', 302, ['Location' => '/products/show?id=' . $id]);
    }

    /** @param array{error?: string, unit_id?: int, input?: array<string, string>} $unitForm */
    private function renderShow(Product $product, bool $canWrite, string $error = '', int $errorWarehouseId = 0, mixed $attempted = '', int $status = 200, array $unitForm = []): Response
    {
        $units = ($this->units ?? new \App\Service\ProductUnitCatalog($this->repository))->forProduct($product);
        $usage = [];
        foreach ($units->all() as $unit) {
            $usage[$unit->id()] = $unit->id() > 0 && ($this->units?->repository()?->isUsed($product->id(), $unit->code()) ?? false);
        }

        return $this->render('products/show.php', [
            'units' => $units,
            'unitUsage' => $usage,
            'unitForm' => $unitForm,
            'canManageUnits' => $canWrite && $this->unitService !== null,
            'product' => $product,
            'category' => $this->categories->findById($product->categoryId()),
            'stocks' => $this->repository->stocksForProduct($product->id()),
            'canWrite' => $canWrite,
            'error' => $error,
            'errorWarehouseId' => $errorWarehouseId,
            'attemptedOverride' => is_string($attempted) ? $attempted : '',
            // Lots with stock per warehouse (D-17, ADR-023); unit cost only for Admin/WarehouseStaff.
            'lots' => $this->lots?->productLots($this->guard->requireAuth(), $product->id()) ?? [],
            'lotNearDays' => $this->lots?->nearDays() ?? 30,
            'canTraceLots' => in_array($this->guard->requireAuth()->role(), [\App\Entity\User::ROLE_ADMIN, \App\Entity\User::ROLE_WAREHOUSE_STAFF], true),
        ], $status);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->products->create($actor, new ProductInput(
                InputValidator::optionalString('sku', $post['sku'] ?? '', 255),
                InputValidator::optionalString('name', $post['name'] ?? '', 120),
                InputValidator::optionalString('unit', $post['unit'] ?? '', 255),
                InputValidator::nonNegativeMoney('purchase_price', $post['purchase_price'] ?? ''),
                InputValidator::nonNegativeMoney('selling_price', $post['selling_price'] ?? ''),
                InputValidator::nonNegativeInt('reorder_point', $post['reorder_point'] ?? ''),
                InputValidator::positiveInt('category_id', $post['category_id'] ?? ''),
                ($post['tracks_expiry'] ?? '') === '1', // D-17 / ADR-023
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->render('products/create.php', [
                'old' => $post,
                'categories' => $this->categories->active(),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            if ($this->imports === null) { throw new \LogicException('CSV import service is not configured.'); }
            $this->imports->import(CsvImport::rowsFromRequest($request), function (array $row) use ($actor): void {
                $this->products->create($actor, new ProductInput(
                    $row['sku'] ?? '',
                    $row['name'] ?? '',
                    $row['unit'] ?? '',
                    InputValidator::nonNegativeMoney('purchase_price', $row['purchase_price'] ?? ''),
                    InputValidator::nonNegativeMoney('selling_price', $row['selling_price'] ?? ''),
                    InputValidator::nonNegativeInt('reorder_point', $row['reorder_point'] ?? ''),
                    InputValidator::positiveInt('category_id', $row['category_id'] ?? ''),
                ));
            });
        } catch (InvalidArgumentException $exception) {
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

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
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
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');

        try {
            $this->products->update($actor, $id, new ProductInput(
                InputValidator::optionalString('sku', $post['sku'] ?? '', 255),
                InputValidator::optionalString('name', $post['name'] ?? '', 120),
                InputValidator::optionalString('unit', $post['unit'] ?? '', 255),
                InputValidator::nonNegativeMoney('purchase_price', $post['purchase_price'] ?? ''),
                InputValidator::nonNegativeMoney('selling_price', $post['selling_price'] ?? ''),
                InputValidator::nonNegativeInt('reorder_point', $post['reorder_point'] ?? ''),
                InputValidator::positiveInt('category_id', $post['category_id'] ?? ''),
                ($post['tracks_expiry'] ?? '') === '1', // D-17 / ADR-023
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->render('products/edit.php', [
                'old' => $post,
                'product' => $this->repository->findById($id),
                'categories' => $this->categories->active(),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function activate(Request $request): Response
    {
        $this->products->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), true);

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function deactivate(Request $request): Response
    {
        $this->products->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), false);

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    /**
     * @param list<Product> $products
     * @return array<int, list<\App\Entity\ProductStock>>
     */
    private function stocksByProduct(array $products): array
    {
        return $this->repository->stocksForProducts(array_map(static fn (Product $product): int => $product->id(), $products));
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = [], int $status = 200): Response
    {
        if (isset($data['result']) && $data['result'] instanceof \App\Support\PaginatedResult) {
            // Stock shown with the unit breakdown (D-16, ADR-022).
            $data['unitSets'] = ($this->units ?? new \App\Service\ProductUnitCatalog($this->repository))->forProducts(array_values(array_filter($data['result']->items(), static fn ($item): bool => $item instanceof Product)));
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $status);
    }
}
