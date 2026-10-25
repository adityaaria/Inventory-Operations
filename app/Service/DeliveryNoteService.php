<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SalesOrder;
use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\DeliveryNoteRepositoryInterface;
use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Security\AuthContext;
use App\Support\OrderNumber;
use App\Support\PaginatedResult;
use App\Support\RequestOrigin;

/**
 * Delivery notes / surat jalan (D-19, ADR-025). createForShipment() runs inside the SO issue transaction (ADR-020),
 * after the stock rows, lots and ledger rows are written, so the note commits or rolls back with the shipment.
 * Access: Admin and Warehouse see all notes; Sales sees notes of their own sales orders only.
 */
final class DeliveryNoteService
{
    public const PER_PAGE = 20;
    public const VEHICLE_MAX = 40;
    public const DRIVER_MAX = 80;

    public function __construct(
        private readonly DeliveryNoteRepositoryInterface $notes,
        private readonly DocumentSequenceRepositoryInterface $sequences,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ?AuditLogRepositoryInterface $audit = null,
    ) {
    }

    /**
     * Optional vehicle (plate) and driver entered at issue, validated before the shipment transaction starts.
     *
     * @param array<string, mixed> $input
     * @return array{vehicle: ?string, driver: ?string}
     */
    public static function shipmentDetails(array $input): array
    {
        $field = static function (string $key, int $max, string $label) use ($input): ?string {
            $value = $input[$key] ?? null;
            if ($value === null) {
                return null;
            }
            if (!is_string($value)) {
                throw new ValidationException($label . ' is invalid.');
            }
            $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            if (mb_strlen($text) > $max) {
                throw new ValidationException($label . ' may have at most ' . $max . ' characters.');
            }

            return $text === '' ? null : $text;
        };

        return ['vehicle' => $field('vehicle', self::VEHICLE_MAX, 'Vehicle'), 'driver' => $field('driver', self::DRIVER_MAX, 'Driver')];
    }

    /**
     * One note per shipment: DO-YYMM-NNNN from the ADR-017 sequence, the customer's current name and address as ship-to,
     * one line per shipped SO line (line unit + base) and one lot row per Issue ledger row (the FEFO allocation of
     * ADR-023). The caller holds the SO lock, so the shipment number is the order's next one.
     *
     * @param array<int, int> $accepted SO item id => base quantity shipped now
     * @param list<array{product_id: int, lot_id: int, lot_code: string, expiry_date: ?string, unit_cost: string, quantity: int}> $shipped
     * @param array{vehicle: ?string, driver: ?string} $details
     */
    public function createForShipment(AuthContext $actor, SalesOrder $order, array $accepted, array $shipped, array $details): int
    {
        $lines = [];
        foreach ($order->items() as $item) {
            $base = $accepted[$item->id()] ?? 0;
            if ($base <= 0) {
                continue;
            }
            $factor = max(1, $item->unitFactor());
            $lots = [];
            foreach ($shipped as $lot) {
                if ((int) $lot['product_id'] === $item->productId()) {
                    $lots[] = ['lot_id' => (int) $lot['lot_id'], 'lot_code' => (string) $lot['lot_code'], 'expiry_date' => $lot['expiry_date'], 'quantity' => (int) $lot['quantity']];
                }
            }
            $lines[] = [
                'sales_order_item_id' => $item->id(),
                'product_id' => $item->productId(),
                'unit_code' => (string) ($item->unitCode() ?? ''),
                'unit_factor' => $factor,
                'unit_quantity' => intdiv($base, $factor),
                'quantity' => $base,
                'lots' => $lots,
            ];
        }
        if ($lines === []) {
            throw new \LogicException('A delivery note needs at least one shipped line.');
        }
        $customer = $this->customers->findById($order->customerId());
        $period = $this->sequences->currentPeriod();
        $number = OrderNumber::format(OrderNumber::DELIVERY, $period, $this->sequences->next(OrderNumber::DELIVERY, $period));
        $shipment = $this->notes->nextShipmentNumber($order->id());
        $id = $this->notes->create([
            'delivery_number' => $number,
            'sales_order_id' => $order->id(),
            'shipment_number' => $shipment,
            'warehouse_id' => $order->sourceWarehouseId(),
            'customer_id' => $order->customerId(),
            'ship_to_name' => $customer?->name() ?? ('Customer #' . $order->customerId()),
            'ship_to_address' => $customer?->address() ?? '',
            'vehicle' => $details['vehicle'],
            'driver' => $details['driver'],
            'prepared_by' => $actor->userId(),
        ], $lines);
        $this->audit?->append($actor->userId(), 'delivery-notes.create', 'delivery_notes', $id, 'success', RequestOrigin::none(), [
            'delivery_number' => $number,
            'sales_order_id' => $order->id(),
            'shipment_number' => $shipment,
            'units' => array_sum(array_column($lines, 'quantity')),
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $query
     * @return PaginatedResult<array<string, mixed>>
     */
    public function search(AuthContext $actor, array $query): PaginatedResult
    {
        $this->assertCanRead($actor);
        $invoiced = is_string($query['invoiced'] ?? null) ? (string) $query['invoiced'] : '';
        if (!in_array($invoiced, ['', 'yes', 'no'], true)) {
            throw new ValidationException('Unknown invoice filter.');
        }
        $term = is_string($query['q'] ?? null) ? mb_substr(trim((string) $query['q']), 0, 80) : '';
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $this->notes->search(['q' => $term, 'invoiced' => $invoiced], is_int($page) ? $page : 1, self::PER_PAGE, $actor->role() === User::ROLE_SALES ? $actor->userId() : null);
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $actor, int $id): array
    {
        $this->assertCanRead($actor);
        $note = $this->notes->find($id) ?? throw new HttpException(404, 'Delivery note not found.');
        $this->assertCanView($actor, $note);

        return $note;
    }

    /** Notes of an order the actor may already view (SO detail page). @return list<array<string, mixed>> */
    public function forOrder(AuthContext $actor, SalesOrder $order): array
    {
        if ($actor->role() === User::ROLE_SALES && $order->createdBy() !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }

        return $this->notes->forSalesOrder($order->id());
    }

    /** @param array<string, mixed> $note */
    public function assertCanView(AuthContext $actor, array $note): void
    {
        $this->assertCanRead($actor);
        if ($actor->role() === User::ROLE_SALES && (int) $note['sales_created_by'] !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanRead(AuthContext $actor): void
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF, User::ROLE_SALES], true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
