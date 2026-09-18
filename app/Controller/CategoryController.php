<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\CategoryService;
use App\Support\CsvImport;
use InvalidArgumentException;

final class CategoryController
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly CategoryRepositoryInterface $repository,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->render('categories/index.php', [
            'categories' => $this->categories->all($actor),
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'error' => '',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->guard->requireUserManagement();

        return $this->render('categories/create.php', ['error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            $post = $request->post();
            $this->categories->create($actor, (string) ($post['name'] ?? ''), (string) ($post['description'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            return $this->render('categories/create.php', ['error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/categories']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            foreach (CsvImport::rowsFromRequest($request) as $row) {
                $this->categories->create($actor, $row['name'] ?? '', $row['description'] ?? '');
            }
        } catch (\Throwable $exception) {
            return $this->render('categories/index.php', [
                'categories' => $this->categories->all($actor),
                'canWrite' => true,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/categories']);
    }

    public function edit(Request $request): Response
    {
        $this->guard->requireUserManagement();
        $category = $this->repository->findById((int) ($request->query()['id'] ?? 0));
        if ($category === null) {
            throw new HttpException(404, 'Category not found.');
        }

        return $this->render('categories/edit.php', ['category' => $category, 'error' => '']);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();
        $post = $request->post();
        $id = (int) ($post['id'] ?? 0);

        try {
            $this->categories->update($actor, $id, (string) ($post['name'] ?? ''), (string) ($post['description'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            return $this->render('categories/edit.php', [
                'category' => $this->repository->findById($id),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => '/categories']);
    }

    public function activate(Request $request): Response
    {
        $this->categories->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), true);

        return new Response('', 302, ['Location' => '/categories']);
    }

    public function deactivate(Request $request): Response
    {
        $this->categories->setActive($this->guard->requireUserManagement(), (int) ($request->post()['id'] ?? 0), false);

        return new Response('', 302, ['Location' => '/categories']);
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
