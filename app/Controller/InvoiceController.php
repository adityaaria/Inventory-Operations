<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Service\InvoiceService;
use App\Support\CsvResponse;
use App\Validation\InputValidator;
use InvalidArgumentException;

/** Invoices with PPN and receivable reports (D-19, ADR-025). HTTP only; InvoiceService authorizes and validates. */
final class InvoiceController
{
    public function __construct(private readonly InvoiceService $invoices, private readonly AuthGuard $guard, private readonly ?\App\Service\InventoryValuationService $valuation = null)
    {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            $result = $this->invoices->search($actor, $request->query());
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $result = $this->invoices->search($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return View::render('invoices/index.php', ['result' => $result, 'query' => $request->query(), 'error' => $error, 'today' => $this->invoices->today(), 'isAdmin' => $actor->role() === User::ROLE_ADMIN], $status);
    }

    /** Admin creates the invoice of a delivery note. */
    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $noteId = InputValidator::positiveInt('delivery_note_id', $request->post()['delivery_note_id'] ?? '');
        try {
            $id = $this->invoices->createFromDeliveryNote($actor, $noteId);
        } catch (InvalidArgumentException $exception) {
            return View::render('invoices/error.php', ['error' => $exception->getMessage(), 'noteId' => $noteId], 422);
        }

        return new Response('', 302, ['Location' => '/invoices/show?id=' . $id]);
    }

    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return $this->renderShow($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));
    }

    public function print(Request $request): Response
    {
        $actor = $this->guard->requireAuth();

        return View::render('invoices/print.php', ['invoice' => $this->invoices->show($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''))]);
    }

    public function payment(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $post = $request->post();
        $id = InputValidator::positiveInt('id', $post['id'] ?? '');
        try {
            $this->invoices->recordPayment($actor, $id, $post);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($actor, $id, $exception->getMessage(), 422, $post);
        }

        return new Response('', 302, ['Location' => '/invoices/show?id=' . $id]);
    }

    public function void(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $id = InputValidator::positiveInt('id', $request->post()['id'] ?? '');
        $reason = $request->post()['reason'] ?? null;
        try {
            $this->invoices->void($actor, $id, $reason);
        } catch (InvalidArgumentException $exception) {
            return $this->renderShow($actor, $id, $exception->getMessage(), 422, [], ['open' => true, 'reason' => is_string($reason) ? $reason : '', 'error' => $exception->getMessage()]);
        }

        return new Response('', 302, ['Location' => '/invoices/show?id=' . $id]);
    }

    public function receivables(): Response
    {
        $actor = $this->guard->requireAuth();

        return View::render('invoices/receivables.php', ['report' => $this->invoices->agingReport($actor), 'error' => '']);
    }

    public function receivablesCsv(): Response
    {
        $actor = $this->guard->requireAuth();

        return CsvResponse::download('receivables-aging.csv', $this->invoices->agingCsv($actor));
    }

    public function salesByCustomer(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            $report = $this->invoices->salesByCustomer($actor, $request->query());
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $report = $this->invoices->salesByCustomer($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return View::render('invoices/sales-by-customer.php', ['report' => $report, 'error' => $error], $status);
    }

    public function salesByCustomerCsv(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            return CsvResponse::download('sales-by-customer.csv', $this->invoices->salesByCustomerCsv($actor, $request->query()));
        } catch (InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }

    /** @param array<string, mixed> $old @param array<string, mixed> $voidDialog */
    private function renderShow(AuthContext $actor, int $id, string $error = '', int $status = 200, array $old = [], array $voidDialog = []): Response
    {
        $invoice = $this->invoices->show($actor, $id);

        return View::render('invoices/show.php', [
            'invoice' => $invoice,
            // D-20 / ADR-026: COGS and margin per line, Admin only.
            'costing' => $actor->role() === User::ROLE_ADMIN ? $this->valuation?->invoiceCosting($actor, (int) $invoice['delivery_note_id'], $invoice['lines']) : null,
            'error' => $error,
            'old' => $old,
            'voidDialog' => $voidDialog,
            'isAdmin' => $actor->role() === User::ROLE_ADMIN,
            'today' => $this->invoices->today(),
            'methods' => InvoiceService::METHODS,
        ], $status);
    }
}
