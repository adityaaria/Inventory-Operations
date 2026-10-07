<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\SupplierRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\AuthContext;
use App\Support\Pagination;
use App\Service\SupplierService;
use App\Support\CsvImport;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class SupplierController
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly SupplierRepositoryInterface $repository,
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

        return $this->render('suppliers/create.php', ['error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->suppliers->create(
                $actor,
                InputValidator::optionalString('name', $post['name'] ?? '', 120),
                InputValidator::optionalString('email', $post['email'] ?? '', 190),
                InputValidator::optionalString('phone', $post['phone'] ?? '', 255),
                InputValidator::optionalString('address', $post['address'] ?? '', 1000),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('suppliers/create.php', ['old' => $post, 'error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/suppliers']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            if ($this->imports === null) throw new \LogicException('CSV import service is not configured.');
            $this->imports->import(CsvImport::rowsFromRequest($request), function (array $row) use ($actor): void {
                $this->suppliers->create(
                    $actor,
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                    $row['address'] ?? '',
                );
            });
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex($actor, $request, $exception->getMessage(), 422);
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
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');

        try {
            $this->suppliers->update(
                $actor,
                $id,
                InputValidator::optionalString('name', $post['name'] ?? '', 120),
                InputValidator::optionalString('email', $post['email'] ?? '', 190),
                InputValidator::optionalString('phone', $post['phone'] ?? '', 255),
                InputValidator::optionalString('address', $post['address'] ?? '', 1000),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('suppliers/edit.php', [
                'old' => $post,
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

    private function renderIndex(AuthContext $actor, Request $request, string $error = '', int $status = 200): Response
    {
        $result = $this->suppliers->paginate($actor, Pagination::fromArray($request->query()));

        return $this->render('suppliers/index.php', [
            'suppliers' => $result->items(),
            'result' => $result,
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'paginationPath' => '/suppliers',
            'paginationLabel' => 'Suppliers',
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
