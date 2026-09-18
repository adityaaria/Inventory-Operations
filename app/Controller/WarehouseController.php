<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\WarehouseService;
use App\Support\CsvImport;
use InvalidArgumentException;

final class WarehouseController
{
    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly WarehouseRepositoryInterface $repository,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->render('warehouses/index.php', [
            'warehouses' => $this->warehouses->all($actor),
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'error' => '',
        ]);
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
            $this->warehouses->create($actor, (string) ($post['name'] ?? ''), (string) ($post['location'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            return $this->render('warehouses/create.php', ['error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/warehouses']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            foreach (CsvImport::rowsFromRequest($request) as $row) {
                $this->warehouses->create($actor, $row['name'] ?? '', $row['location'] ?? '');
            }
        } catch (\Throwable $exception) {
            return $this->render('warehouses/index.php', [
                'warehouses' => $this->warehouses->all($actor),
                'canWrite' => true,
                'error' => $exception->getMessage(),
            ], 422);
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
        $id = (int) ($post['id'] ?? 0);

        try {
            $this->warehouses->update($actor, $id, (string) ($post['name'] ?? ''), (string) ($post['location'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            return $this->render('warehouses/edit.php', [
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
