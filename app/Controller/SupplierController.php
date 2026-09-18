<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\SupplierRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\SupplierService;
use App\Support\CsvImport;
use InvalidArgumentException;

final class SupplierController
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly SupplierRepositoryInterface $repository,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->render('suppliers/index.php', [
            'suppliers' => $this->suppliers->all($actor),
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'error' => '',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('suppliers/create.php', ['error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->suppliers->create(
                $actor,
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                (string) ($post['phone'] ?? ''),
                (string) ($post['address'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('suppliers/create.php', ['error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/suppliers']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            foreach (CsvImport::rowsFromRequest($request) as $row) {
                $this->suppliers->create(
                    $actor,
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                    $row['address'] ?? '',
                );
            }
        } catch (\Throwable $exception) {
            return $this->render('suppliers/index.php', [
                'suppliers' => $this->suppliers->all($actor),
                'canWrite' => true,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/suppliers']);
    }

    public function edit(Request $request): Response
    {
        $this->guard->requireUserManagement();
        $supplier = $this->repository->findById((int) ($request->query()['id'] ?? 0));
        if ($supplier === null) {
            throw new HttpException(404, 'Supplier not found.');
        }

        return $this->render('suppliers/edit.php', ['supplier' => $supplier, 'error' => '']);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();
        $id = (int) ($post['id'] ?? 0);

        try {
            $this->suppliers->update(
                $actor,
                $id,
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                (string) ($post['phone'] ?? ''),
                (string) ($post['address'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('suppliers/edit.php', [
                'supplier' => $this->repository->findById($id),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/suppliers']);
    }

    public function activate(Request $request): Response
    {
        $this->suppliers->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), true);

        return new Response('', 302, ['Location' => '/suppliers']);
    }

    public function deactivate(Request $request): Response
    {
        $this->suppliers->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), false);

        return new Response('', 302, ['Location' => '/suppliers']);
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
