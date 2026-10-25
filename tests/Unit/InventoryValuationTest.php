<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ValuationController;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\StockLedgerEntry;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryBusinessOperationRepository;
use App\Repository\InMemory\InMemoryInventoryValuationRepository;
use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\BusinessOperationService;
use App\Service\DashboardService;
use App\Service\InventoryValuationService;
use App\Service\LotSplit;
use App\Service\StockDelta;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\CostAmount;
use PHPUnit\Framework\TestCase;

/** D-20 / ADR-026: FIFO-per-batch valuation, cost amounts, COGS, transit cost carry, losses, margin and authorization. */
final class InventoryValuationTest extends TestCase
{
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private StockService $stocks;
    private AuthContext $admin;
    private AuthContext $reviewer;
    private AuthContext $warehouse;
    private AuthContext $sales;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockRepository('2026-10-12');
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->stocks = new StockService($this->stock, $this->ledger, new InMemoryAuditLogRepository());
        $this->stock->configureProduct(10, false, '1000.00');
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $this->reviewer = new AuthContext(2, 'reviewer@test', User::ROLE_ADMIN);
        $this->warehouse = new AuthContext(5, 'wh@test', User::ROLE_WAREHOUSE_STAFF);
        $this->sales = new AuthContext(7, 'sales@test', User::ROLE_SALES);
    }

    // ---- Cost amount ----------------------------------------------------------------------------------------------

    public function testCostAmountIsSignedQuantityTimesUnitCostInWholeCentsHalfAwayFromZero(): void
    {
        self::assertSame(3705, CostAmount::cents(3, '12.35'));
        self::assertSame(-3705, CostAmount::cents(-3, '12.35'), 'An issue is the exact negative of the receipt.');
        self::assertSame('-37.05', CostAmount::decimal(-3, '12.35'));
        self::assertSame('0.00', CostAmount::decimal(0, '12.35'));
        self::assertSame(1, CostAmount::cents(1, '0.005'), 'Half a cent rounds up.');
        self::assertSame(-1, CostAmount::cents(-1, '0.005'), 'Half away from zero: a reversal cancels exactly.');
        self::assertSame(0, CostAmount::cents(1, '0.004999'));
        self::assertSame(864, CostAmount::cents(7, '1.234567'), '7 × 1.234567 = 8.641969 → 8.64.');
        self::assertSame(250000000000000, CostAmount::cents(1000000, '2500000.00'), '1,000,000 units × Rp 2,500,000.00 = Rp 2.5 trillion, exact in cents.');
        self::assertSame(15.0, CostAmount::marginPercent(10000, 8500));
        self::assertNull(CostAmount::marginPercent(0, 100));
        foreach (['-1.00', 'abc', '1.2345678', ''] as $invalid) {
            try {
                CostAmount::cents(1, $invalid);
                self::fail('Invalid cost accepted: ' . $invalid);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\OverflowException::class);
        CostAmount::cents(4294967295, '999999999999.99');
    }

    public function testEveryLedgerRowCarriesItsLotCostAndAnIssueOverTwoLotsCostsEachLayer(): void
    {
        // Two receipts at different supplier prices make two cost layers (same code, different cost = two lots).
        $this->stocks->receive(1, [new StockMovement(10, 4, [new LotSplit('L1', null, 4, '1000.00')])], 1, 'PO', 1);
        $this->stocks->receive(1, [new StockMovement(10, 6, [new LotSplit('L1', null, 6, '1030.50')])], 1, 'PO', 2);
        $this->stocks->issue(1, [new StockMovement(10, 7)], 1, 'SO', 9);

        $rows = array_map(static fn (StockLedgerEntry $entry): array => [$entry->movementType(), $entry->delta(), $entry->unitCost(), $entry->costAmount()], $this->ledger->entries());
        self::assertSame([
            ['Receipt', 4, '1000.00', '4000.00'],
            ['Receipt', 6, '1030.50', '6183.00'],
            ['Issue', -4, '1000.00', '-4000.00'],
            ['Issue', -3, '1030.50', '-3091.50'],
        ], $rows, 'FEFO (no expiry: first receipt first) takes the older layer; each Issue row carries its own lot cost.');
        $issueCents = array_sum(array_map(static fn (StockLedgerEntry $entry): int => $entry->movementType() === 'Issue' ? -(int) round((float) $entry->costAmount() * 100) : 0, $this->ledger->entries()));
        self::assertSame(709150, $issueCents, 'COGS = 4 × 1000.00 + 3 × 1030.50.');
        // Lot roll-forward: value = quantity × cost = Σ cost amounts of its rows.
        foreach ($this->stock->lots(10, 1) as $lot) {
            $rowsCents = array_sum(array_map(static fn (StockLedgerEntry $entry): int => $entry->lotId() === $lot->id() ? (int) round((float) $entry->costAmount() * 100) : 0, $this->ledger->entries()));
            self::assertSame(CostAmount::valueCents($lot->quantity(), $lot->unitCost()), $rowsCents);
        }
    }

    public function testTransferCarriesTheSourceLotCostAndDamagedUnitsAreALossAtThatCost(): void
    {
        $this->stock->seedLot(10, 1, 'NOV', '2026-11-01', 3, '10.00');
        $this->stock->seedLot(10, 1, 'DEC', '2026-12-01', 4, '11.00');
        $service = new BusinessOperationService(new InMemoryBusinessOperationRepository(['10:1' => ['quantity' => 7], '10:2' => ['quantity' => 0]]), $this->stocks, new InMemoryAuditLogRepository());
        $id = $service->propose($this->admin, 'Transfer', 1, 2, 'Rebalance', [['product_id' => 10, 'quantity' => 5]]);
        $service->decide($this->reviewer, $id, 'Approved', 'OK');
        $service->dispatch($this->warehouse, $id);
        $service->receive($this->warehouse, $id, [10 => ['good' => 3, 'damaged' => 1]], 'One carton crushed, one missing');

        $rows = array_map(static fn (StockLedgerEntry $entry): array => [$entry->warehouseId(), $entry->delta(), $entry->unitCost(), $entry->costAmount()], $this->ledger->entries());
        self::assertSame([[1, -3, '10.00', '-30.00'], [1, -2, '11.00', '-22.00'], [2, 3, '10.00', '30.00']], $rows, 'Out at the source lot cost, in at the same cost.');
        $lots = $service->show($this->admin, $id)['items'][0]['lots'];
        $lossCents = array_sum(array_map(static fn (array $lot): int => CostAmount::valueCents((int) $lot['damaged_quantity'] + (int) $lot['short_quantity'], (string) $lot['unit_cost']), $lots));
        self::assertSame(2200, $lossCents, 'Damaged + short: 2 units of DEC at 11.00.');
        $outCents = -3000 - 2200;
        self::assertSame(0, -$outCents - 3000 - $lossCents, 'Dispatched value = received value + loss value; nothing left in transit.');
    }

    public function testWriteOffsAndCountShortfallsCarryNegativeCostAtTheLotCost(): void
    {
        $lot = $this->stock->seedLot(10, 1, 'OLD', '2026-09-01', 4, '12.50');
        $this->stocks->adjust([new StockDelta(10, 1, -4, 4, \App\Service\LotTarget::lot($lot, true))], 1, 'ADJUSTMENT', 3, static fn (): null => null);
        $this->stocks->receive(1, [new StockMovement(10, 2, [new LotSplit('NEW', null, 2, '13.00')])], 1, 'PO', 4);
        $new = array_values(array_filter($this->stock->lots(10, 1), static fn (\App\Entity\StockLot $candidate): bool => $candidate->code() === 'NEW'))[0];
        $this->stocks->postCount(1, [new \App\Service\CountPosting(1, 10, $new->id(), null, -1, 'Shelf A')], 1, 8, static fn (array $posted): null => null);

        self::assertSame([['-4', '-50.00'], ['2', '26.00'], ['-1', '-13.00']], array_map(static fn (StockLedgerEntry $entry): array => [(string) $entry->delta(), (string) $entry->costAmount()], $this->ledger->entries()));
    }

    // ---- Read models ---------------------------------------------------------------------------------------------

    public function testValuationGroupsLotValuesAddsTransitAndRollsBackToAnAsOfDate(): void
    {
        $service = new InventoryValuationService($this->repository());
        $current = $service->valuation($this->admin, []);
        self::assertSame([['Main', 3, 3900], ['Branch', 2, 2600]], array_map(static fn (array $row): array => [$row['warehouse_name'], $row['quantity'], $row['value_cents']], $current['rows']));
        self::assertSame(['quantity' => 5, 'value_cents' => 6500, 'lots' => 3], $current['totals']);
        self::assertSame(2200, $current['transit_cents'], 'In transit: 2 units at the cost of the lot they left (11.00).');
        self::assertSame(8700, $current['total_cents']);

        $byLot = $service->valuation($this->admin, ['by' => 'lot']);
        self::assertSame([1100, 2800, 2600], array_column($byLot['rows'], 'value_cents'), 'Lot value = quantity × lot unit cost.');
        $filtered = $service->valuation($this->admin, ['by' => 'product', 'warehouse_id' => '2']);
        self::assertSame(2600, $filtered['totals']['value_cents']);

        // As of 2026-10-05 (the business day ends 17:00 UTC in Asia/Jakarta): roll back every valued row written after it.
        $past = $service->valuation($this->admin, ['as_of' => '2026-10-05']);
        self::assertSame(['quantity' => 12, 'value_cents' => 14500, 'lots' => 3], $past['totals'], 'Before the issue (−4), the transfer (−2) and the count (−1).');
        self::assertSame(0, $past['transit_cents'], 'The transfer left on 10-08.');

        foreach ([['as_of' => '2026-09-30', 'message' => 'valuation starts'], ['as_of' => '2026-10-13', 'message' => 'future'], ['as_of' => '2026-13-01', 'message' => 'YYYY-MM-DD']] as $case) {
            try {
                $service->valuation($this->admin, ['as_of' => $case['as_of']]);
                self::fail('Expected 422 for ' . $case['as_of']);
            } catch (ValidationException $exception) {
                self::assertStringContainsString($case['message'], $exception->getMessage());
            }
        }
        self::assertStringContainsString('in_transit', $service->valuationCsv($this->admin, ['by' => 'product']));
    }

    public function testReconciliationBalancesEveryWarehouseTheTransitLineAndTheTotalToTheCent(): void
    {
        $service = new InventoryValuationService($this->repository());
        $report = $service->reconciliation($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-12']);
        self::assertTrue($report['reconciled']);
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[$row['label']] = $row;
        }
        self::assertSame(['opening' => 0, 'receipts' => 11900, 'issues' => -4400, 'transfers_out' => -2200, 'counts' => -1400, 'closing' => 3900],
            array_intersect_key($rows['Main'], array_flip(['opening', 'receipts', 'issues', 'transfers_out', 'counts', 'closing'])));
        self::assertSame([0, 2200, 0, 2200, 0], [$rows['In transit']['opening'], $rows['In transit']['transfers_in'], $rows['In transit']['transfers_out'], $rows['In transit']['closing'], $rows['In transit']['difference']]);
        self::assertSame(0, $rows['Total']['difference']);
        self::assertSame(8700, $rows['Total']['closing'], 'Total closing = on hand + in transit = the valuation total.');
        self::assertTrue($report['opening_at_valuation_start'], 'The period starts before the valuation start.');
        self::assertSame(1, $report['unvalued_movements']);
        self::assertStringContainsString('In transit', $service->reconciliationCsv($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-12']));
        $this->expectException(ValidationException::class);
        $service->reconciliation($this->admin, ['from' => '2026-09-01', 'to' => '2026-09-30']);
    }

    public function testMarginIsNetRevenueMinusCogsWithUnvaluedLinesAndNotInvoicedShipmentsKeptApart(): void
    {
        $service = new InventoryValuationService($this->repository());
        $report = $service->margin($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'customer']);
        self::assertSame(['revenue_cents' => 14000, 'cogs_cents' => 9500, 'margin_cents' => 4500, 'margin_percent' => 32.1, 'unvalued_revenue_cents' => 3000, 'lines' => 3], $report['totals'],
            'Revenue − COGS over valued lines; revenue shipped before the valuation start is kept apart.');
        self::assertSame([['Toko A', 10000, 5000, 50.0], ['Toko B', 4000, -500, -12.5]], array_map(static fn (array $group): array => [$group['label'], $group['revenue_cents'], $group['margin_cents'], $group['margin_percent']], $report['groups']));
        self::assertCount(1, $report['not_invoiced']);
        self::assertSame(2200, $report['not_invoiced_totals']['cogs_cents']);
        $lines = $service->margin($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'line', 'customer_id' => '2']);
        self::assertSame([-500], array_column($lines['lines'], 'margin_cents'), 'A line sold below its lot cost shows a negative margin.');
        $csv = $service->marginCsv($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        self::assertStringContainsString('shipped_not_invoiced', $csv);
        self::assertStringContainsString('no (shipped before valuation start)', $csv);
        self::assertStringContainsString('TOTAL invoiced', $csv);
    }

    public function testLossesValueTransitDamageAtLotCostAndLedgerRowsAtTheirCostAmount(): void
    {
        $service = new InventoryValuationService($this->repository());
        $report = $service->losses($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        self::assertSame(['ExpiredWriteOff' => 2500, 'TransitDamage' => 2400, 'TransitShortage' => 0, 'CountShortfall' => 1400], array_map(static fn (array $total): int => $total['value_cents'], $report['totals']),
            'Transit damage has no ledger row: 2 units × 12.00 lot cost; ledger rows keep their own cost amount.');
        self::assertSame(6300, $report['total_cents']);
        self::assertStringContainsString('TOTAL Transit damage', $service->lossesCsv($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31']));
    }

    public function testDocumentCostingSumsShipmentLotsAndMarginOfTheInvoicedShipmentsOnly(): void
    {
        $service = new InventoryValuationService($this->repository());
        $costing = $service->orderCosting($this->admin, 40);
        self::assertSame(['cogs_cents' => 6600, 'unvalued_units' => 0, 'invoiced_revenue_cents' => 6000, 'invoiced_cogs_cents' => 4400, 'not_invoiced_cogs_cents' => 2200, 'invoiced_margin_cents' => 1600, 'invoiced_margin_percent' => 26.7], $costing['summary']);
        self::assertSame(2, count($costing['notes']));
        $note = $service->deliveryNoteCosting($this->admin, 501);
        self::assertSame(4400, $note['summary']['cogs_cents']);
        $invoice = $service->invoiceCosting($this->admin, 501, [['delivery_note_line_id' => 7001, 'amount' => '60.00']]);
        self::assertSame(['revenue_cents' => 6000, 'cogs_cents' => 4400, 'margin_cents' => 1600], array_intersect_key($invoice['totals'], array_flip(['revenue_cents', 'cogs_cents', 'margin_cents'])));
    }

    public function testCostAndMarginAreAdminOnlySalesAndWarehouseGet403(): void
    {
        $service = new InventoryValuationService($this->repository());
        foreach ([$this->sales, $this->warehouse] as $actor) {
            foreach ([
                static fn () => $service->margin($actor, []),
                static fn () => $service->marginCsv($actor, []),
                static fn () => $service->valuation($actor, []),
                static fn () => $service->reconciliation($actor, []),
                static fn () => $service->losses($actor, []),
                static fn () => $service->summary($actor),
                static fn () => $service->orderCosting($actor, 40),
                static fn () => $service->deliveryNoteCosting($actor, 501),
                static fn () => $service->invoiceCosting($actor, 501, []),
            ] as $call) {
                try {
                    $call();
                    self::fail('Expected 403 for ' . $actor->role());
                } catch (HttpException $exception) {
                    self::assertSame(403, $exception->statusCode());
                }
            }
        }
        // The controller lets the 403 through (no page, no data) and renders for Admin.
        $controller = $this->controller($this->warehouse, $service);
        try {
            $controller->margin(new Request('GET', '/reports/gross-margin', [], [], []));
            self::fail('Warehouse must get 403 on the margin report.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
        $admin = $this->controller($this->admin, $service);
        self::assertStringContainsString('data-margin-total="4500"', $admin->margin(new Request('GET', '/reports/gross-margin', ['from' => '2026-10-01', 'to' => '2026-10-31'], [], []))->body());
        self::assertStringContainsString('data-valuation-total', $admin->valuation(new Request('GET', '/reports/valuation', [], [], []))->body());
        self::assertStringContainsString('data-reconciled="1"', $admin->reconciliation(new Request('GET', '/reports/valuation-reconciliation', ['from' => '2026-10-01', 'to' => '2026-10-12'], [], []))->body());
        self::assertStringContainsString('data-losses-total="6300"', $admin->losses(new Request('GET', '/reports/inventory-losses', ['from' => '2026-10-01', 'to' => '2026-10-31'], [], []))->body());
        self::assertSame(422, $admin->valuation(new Request('GET', '/reports/valuation', ['as_of' => '2026-09-01'], [], []))->statusCode());
        self::assertSame(422, $admin->marginCsv(new Request('GET', '/reports/gross-margin.csv', ['from' => 'x'], [], []))->statusCode());
        self::assertStringContainsString('text/csv', (string) ($admin->valuationCsv(new Request('GET', '/reports/valuation.csv', [], [], []))->headers()['Content-Type'] ?? ''));
    }

    public function testDashboardInventoryValueIsLotCostOnHandPlusTransitWithTheMasterFigureAsReference(): void
    {
        $dashboard = (new DashboardService(new InMemoryOperationalQueryRepository(), null, new InventoryValuationService($this->repository())))->forActor($this->admin);
        self::assertSame([87.0, 65.0, 22.0, 10000.0], [$dashboard['inventory_value'], $dashboard['inventory_value_on_hand'], $dashboard['inventory_value_in_transit'], $dashboard['inventory_value_master']]);
        $warehouse = (new DashboardService(new InMemoryOperationalQueryRepository(), null, new InventoryValuationService($this->repository())))->forActor($this->warehouse);
        self::assertArrayNotHasKey('inventory_value', $warehouse);
    }

    private function controller(AuthContext $actor, InventoryValuationService $service): ValuationController
    {
        $session = new SessionManager();
        $session->login($actor);
        $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
        $warehouses->method('all')->willReturn([new Warehouse(1, 'Main', '', true)]);
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('all')->willReturn([new Category(1, 'Susu', '', true)]);
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('all')->willReturn([new Customer(1, 'Toko A', '', '', '', true)]);

        return new ValuationController($service, new AuthGuard($session), $warehouses, $categories, $customers);
    }

    /**
     * Fixture (times UTC; a business day in Asia/Jakarta ends 17:00 UTC). Valuation starts 2026-10-02 08:00.
     * Lot 1 L-A Main @ 11.00: receipt 7, issue 4, transfer out 2 → 1 unit (1,100.00); 2 units in transit at 11.00.
     * Lot 3 L-C Main @ 14.00: receipt 3, count −1 → 2 units (2,800.00). Lot 2 L-B Branch @ 13.00: receipt 2 (2,600.00).
     */
    private function repository(): InMemoryInventoryValuationRepository
    {
        $lot = static fn (int $id, string $code, int $warehouse, string $name, int $quantity, string $cost): array => ['lot_id' => $id, 'lot_code' => $code, 'expiry_date' => null, 'product_id' => 10,
            'sku' => 'SKU-010', 'product_name' => 'Susu', 'category_id' => 1, 'category_name' => 'Susu', 'warehouse_id' => $warehouse, 'warehouse_name' => $name, 'unit_cost' => $cost, 'quantity' => $quantity];
        $row = static fn (int $lot, int $warehouse, string $class, int $units, ?int $cents, string $at): array => ['lot_id' => $lot, 'warehouse_id' => $warehouse, 'class' => $class, 'units' => $units, 'cents' => $cents, 'created_at' => $at];

        return new InMemoryInventoryValuationRepository(
            lots: [$lot(1, 'L-A', 1, 'Main', 1, '11.00'), $lot(3, 'L-C', 1, 'Main', 2, '14.00'), $lot(2, 'L-B', 2, 'Branch', 2, '13.00')],
            ledger: [
                $row(1, 1, 'receipt', 7, 7700, '2026-10-03 03:00:00'),
                $row(1, 1, 'issue', -4, -4400, '2026-10-07 03:00:00'),
                $row(1, 1, 'transfer_out', -2, -2200, '2026-10-08 03:00:00'),
                $row(3, 1, 'receipt', 3, 4200, '2026-10-03 04:00:00'),
                $row(3, 1, 'count', -1, -1400, '2026-10-06 03:00:00'),
                $row(2, 2, 'receipt', 2, 2600, '2026-10-03 05:00:00'),
                $row(9, 1, 'receipt', 1, null, '2026-10-01 03:00:00'),     // before the valuation start: no cost
            ],
            transit: [['operation_id' => 77, 'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'category_id' => 1, 'category_name' => 'Susu', 'source_warehouse_id' => 1, 'source_name' => 'Main',
                'destination_warehouse_id' => 2, 'destination_name' => 'Branch', 'lot_code' => 'L-A', 'expiry_date' => null, 'unit_cost' => '11.00', 'dispatched' => 2, 'received' => 0, 'lost' => 0,
                'out_at' => '2026-10-08 03:00:00', 'in_at' => null, 'received_at' => null]],
            losses: [
                ['kind' => 'ExpiredWriteOff', 'occurred_at' => '2026-10-04 03:00:00', 'document_type' => 'ADJUSTMENT', 'document_id' => 3, 'warehouse_id' => 1, 'warehouse_name' => 'Main', 'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'lot_code' => 'OLD', 'units' => 2, 'unit_cost' => '12.50', 'ledger_cents' => 2500],
                ['kind' => 'TransitDamage', 'occurred_at' => '2026-10-20 03:00:00', 'document_type' => 'TRANSFER', 'document_id' => 70, 'warehouse_id' => 2, 'warehouse_name' => 'Branch', 'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'lot_code' => 'L-X', 'units' => 2, 'unit_cost' => '12.00', 'ledger_cents' => null],
                ['kind' => 'CountShortfall', 'occurred_at' => '2026-10-06 03:00:00', 'document_type' => 'STOCK_COUNT', 'document_id' => 8, 'warehouse_id' => 1, 'warehouse_name' => 'Main', 'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'lot_code' => 'L-C', 'units' => 1, 'unit_cost' => '14.00', 'ledger_cents' => 1400],
            ],
            marginLines: [
                self::margin(1, 1, 'Toko A', 10000, 5000, 0),
                self::margin(2, 2, 'Toko B', 4000, 4500, 0),
                self::margin(3, 1, 'Toko A', 3000, 0, 2),
            ],
            notInvoiced: [['delivery_note_id' => 502, 'delivery_number' => 'DO-2610-0002', 'shipped_at' => '2026-10-08 03:00:00', 'customer_id' => 1, 'customer_name' => 'Toko A', 'sales_order_id' => 40, 'order_number' => 'SO-2610-0001',
                'delivery_note_line_id' => 7002, 'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'category_name' => 'Susu', 'unit_code' => 'pcs', 'unit_quantity' => 2, 'base_quantity' => 2, 'unit_price' => '40.00', 'revenue_cents' => 8000, 'cogs_cents' => 2200, 'unvalued_units' => 0]],
            documentLines: [
                ['delivery_note_id' => 501, 'delivery_number' => 'DO-2610-0001', 'shipment_number' => 1, 'shipped_at' => '2026-10-07 03:00:00', 'sales_order_id' => 40, 'delivery_note_line_id' => 7001, 'sales_order_item_id' => 1,
                    'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'unit_code' => 'pcs', 'unit_quantity' => 4, 'base_quantity' => 4, 'unit_price' => '15.00', 'invoice_id' => 9, 'invoice_number' => 'INV-2610-0001',
                    'invoice_cents' => 6000, 'revenue_cents' => 6000, 'cogs_cents' => 4400, 'unvalued_units' => 0, 'lots' => [['lot_code' => 'L-A', 'expiry_date' => null, 'quantity' => 4, 'unit_cost' => '11.00', 'cost_cents' => 4400]]],
                ['delivery_note_id' => 502, 'delivery_number' => 'DO-2610-0002', 'shipment_number' => 2, 'shipped_at' => '2026-10-08 03:00:00', 'sales_order_id' => 40, 'delivery_note_line_id' => 7002, 'sales_order_item_id' => 1,
                    'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'unit_code' => 'pcs', 'unit_quantity' => 2, 'base_quantity' => 2, 'unit_price' => '15.00', 'invoice_id' => null, 'invoice_number' => null,
                    'invoice_cents' => null, 'revenue_cents' => 3000, 'cogs_cents' => 2200, 'unvalued_units' => 0, 'lots' => [['lot_code' => 'L-A', 'expiry_date' => null, 'quantity' => 2, 'unit_cost' => '11.00', 'cost_cents' => 2200]]],
            ],
            startedAt: '2026-10-02 08:00:00',
        );
    }

    /** @return array<string, mixed> */
    private static function margin(int $line, int $customer, string $name, int $revenue, int $cogs, int $unvalued): array
    {
        return ['invoice_id' => $line, 'invoice_number' => 'INV-2610-000' . $line, 'invoice_date' => '2026-10-10', 'customer_id' => $customer, 'customer_name' => $name, 'sales_order_id' => 40, 'order_number' => 'SO-2610-0001',
            'delivery_note_id' => 501, 'delivery_number' => 'DO-2610-0001', 'invoice_line_id' => $line, 'delivery_note_line_id' => 7000 + $line, 'product_id' => 10, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'category_name' => 'Susu',
            'unit_code' => 'pcs', 'unit_quantity' => 1, 'base_quantity' => 1, 'unit_price' => '1.00', 'revenue_cents' => $revenue, 'cogs_cents' => $cogs, 'unvalued_units' => $unvalued];
    }
}
