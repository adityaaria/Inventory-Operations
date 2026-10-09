<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\AuthContext;
use App\Support\Pagination;
use App\Service\UserService;
use App\Support\CsvImport;
use InvalidArgumentException;
use App\Validation\InputValidator;

final class UserController
{
    private const INDEX_PATH = '/users';

    public function __construct(
        private readonly UserService $users,
        private readonly UserRepositoryInterface $repository,
        private readonly AuthGuard $guard,
        private readonly ?\App\Service\CsvImportService $imports = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->renderIndex($this->guard->requireUserManagement(), $request);
    }

    public function create(): Response
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
                InputValidator::optionalString('name', $post['name'] ?? '', 120),
                InputValidator::optionalString('email', $post['email'] ?? '', 190),
                InputValidator::optionalString('password', $post['password'] ?? '', 255),
                InputValidator::optionalString('role', $post['role'] ?? '', 255),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->render('users/create.php', [
                'old' => $post,
                'roles' => User::ROLES,
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
                $this->users->createUser(
                    $actor,
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['password'] ?? '',
                    $row['role'] ?? '',
                );
            });
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex($actor, $request, $exception->getMessage(), 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
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
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');

        try {
            $this->users->updateUser(
                $actor,
                $id,
                InputValidator::optionalString('name', $post['name'] ?? '', 120),
                InputValidator::optionalString('email', $post['email'] ?? '', 190),
                InputValidator::optionalString('role', $post['role'] ?? '', 255),
            );
        } catch (InvalidArgumentException $exception) {
            $user = $this->repository->findById($id);
            return $this->render('users/edit.php', [
                'old' => $post,
                'roles' => User::ROLES,
                'user' => $user,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function activate(Request $request): Response
    {
        $this->users->setActive($this->guard->requireUserManagement(), $this->idFromPost($request), true);

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    public function deactivate(Request $request): Response
    {
        $this->users->setActive($this->guard->requireUserManagement(), $this->idFromPost($request), false);

        return new Response('', 302, ['Location' => self::INDEX_PATH]);
    }

    private function renderIndex(AuthContext $actor, Request $request, string $error = '', int $status = 200): Response
    {
        $result = $this->users->paginate($actor, Pagination::fromArray($request->query()));

        return $this->render('users/index.php', [
            'users' => $result->items(),
            'result' => $result,
            'paginationPath' => self::INDEX_PATH,
            'paginationLabel' => 'Users',
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

    private function idFromQuery(Request $request): int
    {
        return (int) ($request->query()['id'] ?? 0);
    }

    private function idFromPost(Request $request): int
    {
        return (int) ($request->post()['id'] ?? 0);
    }
}
