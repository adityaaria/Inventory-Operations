<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Service\PriceListService;
use App\Validation\InputValidator;
use InvalidArgumentException;

/** Customer price lists (D-19, ADR-025), Admin only. HTTP only; PriceListService authorizes and validates. */
final class PriceListController
{
    public function __construct(
        private readonly PriceListService $lists,
        private readonly AuthGuard $guard,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    public function index(): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->renderIndex($actor);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->renderShow($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            $id = $this->lists->create($actor, $request->post());
        } catch (InvalidArgumentException $exception) {
            return $this->renderIndex($actor, $exception->getMessage(), 422, $request->post());
        }

        return $this->redirect($id);
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');

        return $this->attempt($actor, $id, fn () => $this->lists->update($actor, $id, $request->post()));
    }

    public function addItem(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');

        return $this->attempt($actor, $id, fn () => $this->lists->addItem($actor, $id, $request->post()), $request->post());
    }

    public function updateItem(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');

        return $this->attempt($actor, $id, fn () => $this->lists->updateItem($actor, InputValidator::positiveInt('item_id', $request->post()['item_id'] ?? ''), $request->post()));
    }

    public function deleteItem(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');

        return $this->attempt($actor, $id, fn () => $this->lists->deleteItem($actor, InputValidator::positiveInt('item_id', $request->post()['item_id'] ?? '')));
    }

    /** Assign a customer to this list (`customer_id`), or remove one (`remove=1`). */
    public function assign(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');

        return $this->attempt($actor, $id, fn () => $this->lists->assign($actor, InputValidator::positiveInt('customer_id', $post['customer_id'] ?? ''), ($post['remove'] ?? '') === '1' ? null : $id));
    }

    /** @param array<string, mixed> $old */
    private function attempt(AuthContext $actor, int $id, callable $change, array $old = []): Response
    {
        try {
            $change();
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($actor, $id, $exception->getMessage(), 422, $old);
        }

        return $this->redirect($id);
    }

    private function redirect(int $id): Response
    {
        return new Response('', 302, ['Location' => '/price-lists/show?id=' . $id]);
    }

    /** @param array<string, mixed> $old */
    private function renderIndex(AuthContext $actor, string $error = '', int $status = 200, array $old = []): Response
    {
        return View::render('price-lists/index.php', ['lists' => $this->lists->all($actor), 'error' => $error, 'old' => $old], $status);
    }

    /** @param array<string, mixed> $old */
    private function renderShow(AuthContext $actor, int $id, string $error = '', int $status = 200, array $old = []): Response
    {
        $list = $this->lists->show($actor, $id);

        return View::render('price-lists/show.php', ['list' => $list, 'error' => $error, 'old' => $old, 'allCustomers' => $this->customers->all(), 'products' => $this->products->active()], $status);
    }
}
