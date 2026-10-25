<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Security\AuthGuard;
use App\Service\DeliveryNoteService;
use App\Validation\InputValidator;
use InvalidArgumentException;

/** Delivery notes / surat jalan (D-19, ADR-025). HTTP only; DeliveryNoteService authorizes (Sales: own SOs only). */
final class DeliveryNoteController
{
    public function __construct(
        private readonly DeliveryNoteService $notes,
        private readonly AuthGuard $guard,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ?\App\Service\InventoryValuationService $valuation = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        try {
            $result = $this->notes->search($actor, $request->query());
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $result = $this->notes->search($actor, []);
            $error = $exception->getMessage();
            $status = 422;
        }

        return View::render('delivery-notes/index.php', ['result' => $result, 'query' => $request->query(), 'error' => $error, 'canInvoice' => $actor->role() === User::ROLE_ADMIN], $status);
    }

    /** Printable surat jalan; the page itself is the print layout (window.print()). */
    public function show(Request $request): Response
    {
        $actor = $this->guard->requireAuth();
        $note = $this->notes->show($actor, InputValidator::positiveInt('id', $request->query()['id'] ?? ''));

        return View::render('delivery-notes/show.php', [
            'note' => $note,
            'customer' => $this->customers->findById((int) $note['customer_id']),
            'canInvoice' => $actor->role() === User::ROLE_ADMIN,
            'canSeeInvoice' => in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_SALES], true),
            // D-20 / ADR-026: COGS and margin only for Admin (the service refuses everyone else).
            'costing' => $actor->role() === User::ROLE_ADMIN ? $this->valuation?->deliveryNoteCosting($actor, (int) $note['id']) : null,
        ]);
    }
}
