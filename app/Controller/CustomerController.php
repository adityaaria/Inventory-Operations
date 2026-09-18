<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\CustomerService;
use App\Support\CsvImport;
use InvalidArgumentException;

final class CustomerController
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerRepositoryInterface $repository,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->render('customers/index.php', [
            'customers' => $this->customers->all($actor),
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'error' => '',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('customers/create.php', ['error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->customers->create(
                $actor,
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                (string) ($post['phone'] ?? ''),
                (string) ($post['address'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('customers/create.php', ['error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/customers']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            foreach (CsvImport::rowsFromRequest($request) as $row) {
                $this->customers->create(
                    $actor,
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                    $row['address'] ?? '',
                );
            }
        } catch (\Throwable $exception) {
            return $this->render('customers/index.php', [
                'customers' => $this->customers->all($actor),
                'canWrite' => true,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/customers']);
    }

    public function edit(Request $request): Response
    {
        $this->guard->requireUserManagement();
        $customer = $this->repository->findById((int) ($request->query()['id'] ?? 0));
        if ($customer === null) {
            throw new HttpException(404, 'Customer not found.');
        }

        return $this->render('customers/edit.php', ['customer' => $customer, 'error' => '']);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();
        $id = (int) ($post['id'] ?? 0);

        try {
            $this->customers->update(
                $actor,
                $id,
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                (string) ($post['phone'] ?? ''),
                (string) ($post['address'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('customers/edit.php', [
                'customer' => $this->repository->findById($id),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/customers']);
    }

    public function activate(Request $request): Response
    {
        $this->customers->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), true);

        return new Response('', 302, ['Location' => '/customers']);
    }

    public function deactivate(Request $request): Response
    {
        $this->customers->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), false);

        return new Response('', 302, ['Location' => '/customers']);
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
