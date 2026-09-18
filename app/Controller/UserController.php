<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\UserService;
use App\Support\CsvImport;
use InvalidArgumentException;

final class UserController
{
    public function __construct(
        private readonly UserService $users,
        private readonly UserRepositoryInterface $repository,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $users = $this->users->listUsers($actor);

        return $this->render('users/index.php', ['users' => $users, 'error' => '']);
    }

    public function create(Request $request): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('users/create.php', ['roles' => User::ROLES, 'error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();

        try {
            $this->users->createUser(
                $actor,
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                (string) ($post['password'] ?? ''),
                (string) ($post['role'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('users/create.php', [
                'roles' => User::ROLES,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/users']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            foreach (CsvImport::rowsFromRequest($request) as $row) {
                $this->users->createUser(
                    $actor,
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['password'] ?? '',
                    $row['role'] ?? '',
                );
            }
        } catch (\Throwable $exception) {
            return $this->render('users/index.php', [
                'users' => $this->users->listUsers($actor),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/users']);
    }

    public function edit(Request $request): Response
    {
        $this->guard->requireUserManagement();
        $user = $this->repository->findById($this->idFromQuery($request));
        if ($user === null) {
            throw new HttpException(404, 'User not found.');
        }

        return $this->render('users/edit.php', ['roles' => User::ROLES, 'user' => $user, 'error' => '']);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();
        $id = (int) ($post['id'] ?? 0);

        try {
            $this->users->updateUser(
                $actor,
                $id,
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                (string) ($post['role'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            $user = $this->repository->findById($id);
            return $this->render('users/edit.php', [
                'roles' => User::ROLES,
                'user' => $user,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/users']);
    }

    public function activate(Request $request): Response
    {
        $this->users->setActive($this->guard->requireUserManagement(), $this->idFromPost($request), true);

        return new Response('', 302, ['Location' => '/users']);
    }

    public function deactivate(Request $request): Response
    {
        $this->users->setActive($this->guard->requireUserManagement(), $this->idFromPost($request), false);

        return new Response('', 302, ['Location' => '/users']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $view, array $data = [], int $status = 200): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view;
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '', $status);
    }

    private function idFromQuery(Request $request): int
    {
        return (int) ($request->query()['id'] ?? 0);
    }

    private function idFromPost(Request $request): int
    {
        return (int) ($request->post()['id'] ?? 0);
    }
}
