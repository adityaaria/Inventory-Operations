<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Security\AuthContext;

final class DashboardService
{
    public function __construct(
        private readonly OperationalQueryRepositoryInterface $queries,
        private readonly ?InvoiceService $invoices = null,
        private readonly ?InventoryValuationService $valuation = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function forActor(AuthContext $actor): array
    {
        if ($actor->role() === User::ROLE_SALES) {
            return ['role' => User::ROLE_SALES] + $this->queries->salesDashboard($actor->userId());
        }
        if ($actor->role() === User::ROLE_WAREHOUSE_STAFF) {
            $dashboard = $this->queries->warehouseDashboard();
            $dashboard['low_stock_count'] = count($dashboard['low_stock_rows']);

            return ['role' => User::ROLE_WAREHOUSE_STAFF] + $dashboard;
        }

        $dashboard = ['role' => User::ROLE_ADMIN] + $this->queries->adminDashboard();
        if ($this->valuation !== null) {
            // D-20 / ADR-026: the Inventory Value card is the FIFO-per-batch value (lot quantity × lot unit cost) on hand
            // plus goods in transit at their lot cost. The former figure (on-hand quantity × today's master purchase
            // price) stays as a labelled reference: it is a replacement-cost estimate, not the inventory value.
            $value = $this->valuation->summary($actor);
            $dashboard['inventory_value_master'] = $dashboard['inventory_value'];
            $dashboard['inventory_value'] = $value['total_cents'] / 100.0;
            $dashboard['inventory_value_on_hand'] = $value['on_hand_cents'] / 100.0;
            $dashboard['inventory_value_in_transit'] = $value['in_transit_cents'] / 100.0;
            $dashboard['inventory_value_unvalued_transit_units'] = $value['unvalued_in_transit_units'];
        }
        if ($this->invoices !== null) {
            // D-19 / ADR-025: outstanding receivables and the overdue part (computed from invoices and payments).
            $receivables = $this->invoices->receivablesSummary();
            $dashboard['receivables_outstanding'] = $receivables['outstanding_cents'] / 100;
            $dashboard['receivables_overdue'] = $receivables['overdue_cents'] / 100;
            $dashboard['receivables_open_invoices'] = $receivables['open_invoices'];
        }

        return $dashboard;
    }
}
