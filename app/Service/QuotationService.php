<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\QuotationRepositoryInterface;
use App\Repository\Contract\TransactionManagerInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthContext;
use App\Support\BusinessDate;
use App\Support\OrderNumber;
use App\Support\PaginatedResult;
use App\Support\QuotationStatus;
use App\Support\RequestOrigin;

/**
 * Quotations (D-19, ADR-025). Lines are priced exactly like SO lines (customer price list tier or master, override
 * with reason, Sales cap, cost floor; SalesLineBuilder). Lifecycle Draft → Sent → Accepted | Rejected, Cancelled from
 * Draft, Sent or an unconverted Accepted quotation; Expired is computed from valid_until (QuotationStatus).
 * An Accepted, still valid quotation converts to exactly one SO Draft in one transaction (quotation row lock + UNIQUE).
 * Access: Sales creates and acts on their own quotations, Admin on all; Warehouse 403. Enforced here.
 */
final class QuotationService
{
    public const PER_PAGE = 20;
    public const DEFAULT_VALIDITY_DAYS = 14;
    public const MAX_VALIDITY_DAYS = 365;

    public function __construct(
        private readonly QuotationRepositoryInterface $quotations,
        private readonly DocumentSequenceRepositoryInterface $sequences,
        private readonly ProductRepositoryInterface $products,
        private readonly CustomerRepositoryInterface $customers,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly SalesOrderService $salesOrders,
        private readonly TransactionManagerInterface $transactions,
        private readonly ProductUnitCatalog $units,
        private readonly OrderLinePricing $pricing = new OrderLinePricing(),
        private readonly ?PriceResolver $prices = null,
        private readonly ?AuditLogRepositoryInterface $audit = null,
    ) {
    }

    public function today(): string
    {
        return $this->quotations->today();
    }

    public function defaultValidUntil(): string
    {
        return (new \DateTimeImmutable($this->today()))->modify('+' . self::DEFAULT_VALIDITY_DAYS . ' day')->format('Y-m-d');
    }

    public function salesMaxDiscountPercent(): int
    {
        return $this->pricing->salesMaxDiscountPercent();
    }

    /**
     * @param list<array{product_id: int, quantity: int, unit_code?: string|null, price?: mixed, price_reason?: mixed}> $items
     */
    public function create(AuthContext $actor, int $customerId, int $warehouseId, mixed $validUntil, mixed $notes, array $items): int
    {
        $this->assertCanCreate($actor);
        $customer = $this->customers->findById($customerId);
        if ($customer === null || !$customer->isActive()) {
            throw new ValidationException('Active customer is required.');
        }
        $warehouse = $this->warehouses->findById($warehouseId);
        if ($warehouse === null || !$warehouse->isActive()) {
            throw new ValidationException('Active warehouse is required.');
        }
        $today = $this->today();
        $until = is_string($validUntil) && trim($validUntil) !== '' ? trim($validUntil) : $this->defaultValidUntil();
        if (!BusinessDate::isValidDate($until) || $until < $today) {
            throw new ValidationException('Valid until must be a date from today (' . $today . ') on.');
        }
        if (BusinessDate::daysBetween($today, $until) > self::MAX_VALIDITY_DAYS) {
            throw new ValidationException('A quotation may be valid for at most ' . self::MAX_VALIDITY_DAYS . ' days.');
        }
        $text = is_string($notes) ? trim($notes) : '';
        if (mb_strlen($text) > 500) {
            throw new ValidationException('Notes may have at most 500 characters.');
        }
        $lines = (new SalesLineBuilder($this->products, $this->units, $this->pricing, $this->prices))->lines($actor, $customerId, $items);

        return $this->transactions->run(function () use ($actor, $customerId, $warehouseId, $today, $until, $text, $lines): int {
            $period = $this->sequences->currentPeriod();
            $number = OrderNumber::format(OrderNumber::QUOTATION, $period, $this->sequences->next(OrderNumber::QUOTATION, $period));
            $this->units->confirmSnapshots($lines);
            $id = $this->quotations->create($number, $customerId, $warehouseId, $actor->userId(), $today, $until, $text, $lines);
            $this->audit?->append($actor->userId(), 'quotations.create', 'quotations', $id, 'success', RequestOrigin::none(), [
                'quotation_number' => $number, 'customer_id' => $customerId, 'valid_until' => $until, 'lines' => count($lines),
                'price_list_lines' => count(array_filter($lines, static fn (array $line): bool => $line['price_source'] === PriceResolver::SOURCE_PRICE_LIST)),
            ]);

            return $id;
        });
    }

    public function send(AuthContext $actor, int $id): void
    {
        $this->transition($actor, $id, 'send', function (array $quotation, string $today): void {
            $this->assertOpenAndValid($quotation, $today, QuotationStatus::DRAFT, 'sent');
            $this->quotations->markSent((int) $quotation['id']);
        });
    }

    /** Records the customer's acceptance (by the owner or an Admin). */
    public function accept(AuthContext $actor, int $id): void
    {
        $this->transition($actor, $id, 'accept', function (array $quotation, string $today) use ($actor): void {
            $this->assertOpenAndValid($quotation, $today, QuotationStatus::SENT, 'accepted');
            $this->quotations->markAccepted((int) $quotation['id'], $actor->userId());
        });
    }

    public function reject(AuthContext $actor, int $id, mixed $reason): void
    {
        $reason = BusinessOperationInput::reason($reason);
        $this->transition($actor, $id, 'reject', function (array $quotation) use ($actor, $reason): void {
            if ($quotation['status'] !== QuotationStatus::SENT) {
                throw new ValidationException('Only a Sent quotation can be rejected.');
            }
            $this->quotations->markRejected((int) $quotation['id'], $actor->userId(), $reason);
        }, ['reason' => $reason]);
    }

    public function cancel(AuthContext $actor, int $id): void
    {
        $this->transition($actor, $id, 'cancel', function (array $quotation) use ($actor): void {
            if (!in_array($quotation['status'], [QuotationStatus::DRAFT, QuotationStatus::SENT, QuotationStatus::ACCEPTED], true) || $quotation['sales_order_id'] !== null) {
                throw new ValidationException('Only a Draft, Sent or unconverted Accepted quotation can be cancelled.');
            }
            $this->quotations->markCancelled((int) $quotation['id'], $actor->userId());
        });
    }

    /**
     * Accepted → SO Draft (one transaction). The quotation row is locked first, so a second conversion waits and then
     * finds the SO (422); sales_orders.quotation_id UNIQUE is the final guard. Refused when the quotation is no longer
     * valid. The quoted prices carry over and are re-validated (cost floor, Sales cap) by SalesOrderService.
     */
    public function convert(AuthContext $actor, int $id): int
    {
        $this->assertCanCreate($actor);

        return $this->transactions->run(function () use ($actor, $id): int {
            $quotation = $this->quotations->lock($id) ?? throw new ValidationException('Quotation not found.');
            $this->assertOwnOrAdmin($actor, $quotation);
            if ($quotation['sales_order_id'] !== null) {
                throw new ValidationException('Quotation ' . $quotation['quotation_number'] . ' was already converted to ' . $quotation['sales_order_number'] . '.');
            }
            if ($quotation['status'] !== QuotationStatus::ACCEPTED) {
                throw new ValidationException('Only an Accepted quotation can be converted to a sales order.');
            }
            $today = $this->today();
            if (!QuotationStatus::isValidOn($quotation, $today)) {
                throw new ValidationException('Quotation ' . $quotation['quotation_number'] . ' was valid until ' . $quotation['valid_until'] . '; its prices can no longer be used. Create a new quotation.');
            }
            $order = $this->salesOrders->createDraftFromQuotation($actor, (int) $quotation['created_by'], (int) $quotation['customer_id'], (int) $quotation['source_warehouse_id'], $id, $quotation['lines']);
            $this->audit?->append($actor->userId(), 'quotations.convert', 'quotations', $id, 'success', RequestOrigin::none(), [
                'quotation_number' => $quotation['quotation_number'], 'sales_order_id' => $order->id(), 'order_number' => $order->orderNumber(),
            ]);

            return $order->id();
        });
    }

    /** @return array<string, mixed> quotation + effective status and allowed actions for the actor */
    public function show(AuthContext $actor, int $id): array
    {
        $this->assertCanRead($actor);
        $quotation = $this->quotations->find($id) ?? throw new HttpException(404, 'Quotation not found.');
        $this->assertOwnOrAdmin($actor, $quotation);
        $today = $this->today();
        $effective = QuotationStatus::effective($quotation, $today);
        $valid = QuotationStatus::isValidOn($quotation, $today);

        return $quotation + [
            'effective_status' => $effective,
            'today' => $today,
            'can_send' => $effective === QuotationStatus::DRAFT,
            'can_decide' => $effective === QuotationStatus::SENT,
            'can_cancel' => in_array($effective, [QuotationStatus::DRAFT, QuotationStatus::SENT, QuotationStatus::EXPIRED], true) || ($quotation['status'] === QuotationStatus::ACCEPTED && $quotation['sales_order_id'] === null),
            'can_convert' => $quotation['status'] === QuotationStatus::ACCEPTED && $quotation['sales_order_id'] === null && $valid,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return PaginatedResult<array<string, mixed>>
     */
    public function search(AuthContext $actor, array $query): PaginatedResult
    {
        $this->assertCanRead($actor);
        $status = is_string($query['status'] ?? null) ? (string) $query['status'] : '';
        if ($status !== '' && !in_array($status, QuotationStatus::FILTERS, true)) {
            throw new ValidationException('Unknown quotation status filter.');
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $term = is_string($query['q'] ?? null) ? mb_substr(trim((string) $query['q']), 0, 80) : '';

        return $this->quotations->search(['q' => $term, 'status' => $status, 'today' => $this->today()], is_int($page) ? $page : 1, self::PER_PAGE,
            $actor->role() === User::ROLE_SALES ? $actor->userId() : null);
    }

    public function assertCanCreate(AuthContext $actor): void
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_SALES], true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    /** @param callable(array<string, mixed>, string): void $change @param array<string, scalar> $metadata */
    private function transition(AuthContext $actor, int $id, string $action, callable $change, array $metadata = []): void
    {
        $this->assertCanCreate($actor);
        $this->transactions->run(function () use ($actor, $id, $action, $change, $metadata): void {
            $quotation = $this->quotations->lock($id) ?? throw new ValidationException('Quotation not found.');
            $this->assertOwnOrAdmin($actor, $quotation);
            $change($quotation, $this->today());
            $this->audit?->append($actor->userId(), 'quotations.' . $action, 'quotations', $id, 'success', RequestOrigin::none(), ['quotation_number' => $quotation['quotation_number'], 'previous_status' => $quotation['status']] + $metadata);
        });
    }

    /** @param array<string, mixed> $quotation */
    private function assertOpenAndValid(array $quotation, string $today, string $from, string $verb): void
    {
        if ($quotation['status'] !== $from) {
            throw new ValidationException('Only a ' . $from . ' quotation can be ' . $verb . '.');
        }
        if (!QuotationStatus::isValidOn($quotation, $today)) {
            throw new ValidationException('Quotation ' . $quotation['quotation_number'] . ' expired on ' . $quotation['valid_until'] . ' and can no longer be ' . $verb . '.');
        }
    }

    /** @param array<string, mixed> $quotation */
    private function assertOwnOrAdmin(AuthContext $actor, array $quotation): void
    {
        if ($actor->role() === User::ROLE_ADMIN) {
            return;
        }
        if ($actor->role() !== User::ROLE_SALES || (int) $quotation['created_by'] !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanRead(AuthContext $actor): void
    {
        $this->assertCanCreate($actor);
    }
}
