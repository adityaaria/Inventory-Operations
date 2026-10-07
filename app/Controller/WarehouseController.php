<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\AuthContext;
use App\Support\Pagination;
use App\Service\WarehouseService;
use App\Support\CsvImport;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class WarehouseController
{
    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly WarehouseRepositoryInterface $repository,
        private readonly AuthGuard $guard,
        private readonly ?\App\Service\CsvImportService $imports = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->renderIndex($this->guard->requireAuth(), $request);
    }

    public function create(Request $request): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('warehouses/create.php', ['error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->warehouses->create($actor, InputValidator::optionalString('name', $post['name'] ?? '', 120), InputValidator::optionalString('location', $post['location'] ?? '', 255));
        } catch (InvalidArgumentException $exception) {
            return $this->render('warehouses/create.php', ['old' => $post, 'error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/warehouses']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            if ($this->imports === null) { throw new \LogicException('CSV import service is not configured.'); }
            $this->imports->import(CsvImport::rowsFromRequest($request), function (array $row) use ($actor): void {
                $this->warehouses->create($actor, $row['name'] ?? '', $row['location'] ?? '');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex($actor, $request, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => '/warehouses']);
    }

    public function edit(Request $request): Response
    {
        $this->guard->requireUserManagement();
        $warehouse = $this->repository->findById((int) ($request->query()['id'] ?? 0));
        if ($warehouse === null) {
            throw new HttpException(404, 'Warehouse not found.');
        }

        return $this->render('warehouses/edit.php', ['warehouse' => $warehouse, 'error' => '']);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');

        try {
            $this->warehouses->update($actor, $id, InputValidator::optionalString('name', $post['name'] ?? '', 120), InputValidator::optionalString('location', $post['location'] ?? '', 255));
        } catch (InvalidArgumentException $exception) {
            return $this->render('warehouses/edit.php', [
                'old' => $post,
                'warehouse' => $this->repository->findById($id),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/warehouses']);
    }

    public function activate(Request $request): Response
    {
        $this->warehouses->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), true);

        return new Response('', 302, ['Location' => '/warehouses']);
    }

    public function deactivate(Request $request): Response
    {
        $this->warehouses->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), false);

        return new Response('', 302, ['Location' => '/warehouses']);
    }

    private function renderIndex(AuthContext $actor, Request $request, string $error = '', int $status = 200): Response
    {
        $result = $this->warehouses->paginate($actor, Pagination::fromArray($request->query()));

        return $this->render('warehouses/index.php', [
            'warehouses' => $result->items(),
            'result' => $result,
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'paginationPath' => '/warehouses',
            'paginationLabel' => 'Warehouses',
            'paginationQuery' => $request->query(),
            'error' => $error,
        ], $status);
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
