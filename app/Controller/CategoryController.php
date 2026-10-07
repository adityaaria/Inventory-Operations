<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\AuthContext;
use App\Support\Pagination;
use App\Service\CategoryService;
use App\Support\CsvImport;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class CategoryController
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly CategoryRepositoryInterface $repository,
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

        return $this->render('categories/create.php', ['error' => '']);
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        $post = $request->post();
        try {
            $this->categories->create($actor, InputValidator::optionalString('name', $post['name'] ?? '', 120), InputValidator::optionalString('description', $post['description'] ?? '', 255));
        } catch (InvalidArgumentException $exception) {
            return $this->render('categories/create.php', ['old' => $post, 'error' => $exception->getMessage()], 422);
        }

        return new Response('', 302, ['Location' => '/categories']);
    }

    public function import(Request $request): Response
    {
        $actor = $this->guard->requireUserManagement();

        try {
            if ($this->imports === null) { throw new \LogicException('CSV import service is not configured.'); }
            $this->imports->import(CsvImport::rowsFromRequest($request), function (array $row) use ($actor): void {
                $this->categories->create($actor, $row['name'] ?? '', $row['description'] ?? '');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex($actor, $request, $exception->getMessage(), 422);
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
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');

        try {
            $this->categories->update($actor, $id, InputValidator::optionalString('name', $post['name'] ?? '', 120), InputValidator::optionalString('description', $post['description'] ?? '', 255));
        } catch (InvalidArgumentException $exception) {
            return $this->render('categories/edit.php', [
                'old' => $post,
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

    private function renderIndex(AuthContext $actor, Request $request, string $error = '', int $status = 200): Response
    {
        $result = $this->categories->paginate($actor, Pagination::fromArray($request->query()));

        return $this->render('categories/index.php', [
            'categories' => $result->items(),
            'result' => $result,
            'canWrite' => $actor->role() === \App\Entity\User::ROLE_ADMIN,
            'paginationPath' => '/categories',
            'paginationLabel' => 'Categories',
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
