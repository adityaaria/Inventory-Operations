<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\Api\BarcodeController;
use App\Controller\StockCountController;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Entity\StockLot;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryProductUnitRepository;
use App\Repository\InMemory\InMemoryStockCountRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\BarcodeService;
use App\Service\CountPosting;
use App\Service\LotSplit;
use App\Service\ProductUnitCatalog;
use App\Service\StockCountService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\CountTolerance;
use PHPUnit\Framework\TestCase;

/** D-18 / ADR-024: barcode lookup and stock count sessions (blind count, variance with movements, recount, posting). */
final class StockCountTest extends TestCase
{
    private const TODAY = '2026-10-12';
    private const EAN_PCS = '8991000202612';
    private const EAN_KARTON = '8991000202636';

    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private StockService $stocks;
    private InMemoryProductRepository $products;
    private InMemoryProductUnitRepository $units;
    private InMemoryStockCountRepository $countRepository;
    private StockCountService $counts;
    private AuthContext $admin;
    private AuthContext $reviewer;
    private AuthContext $counter;
    private AuthContext $recounter;
    private AuthContext $otherStaff;
    private AuthContext $sales;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockRepository(self::TODAY);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->stocks = new StockService($this->stock, $this->ledger, $this->audit);
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-010', 'Susu UHT', 'pcs', 1000.0, 1500.0, 5, 1, true, true),
            new Product(11, 'SKU-011', 'Sabun', 'pcs', 500.0, 800.0, 5, 2, true, false),
            new Product(12, 'SKU-012', 'Old soap', 'pcs', 500.0, 800.0, 5, 2, false, false),
        ]);
        $this->stock->configureProduct(10, true, '1000.00');
        $this->stock->configureProduct(11, false, '500.00');
        $this->units = new InMemoryProductUnitRepository([
            new ProductUnit(1, 10, 'pcs', 1, self::EAN_PCS, null, null, true),
            new ProductUnit(2, 10, 'karton', 24, self::EAN_KARTON, 22000.0, 30000.0, false, true, true),
            new ProductUnit(3, 11, 'pcs', 1, '8991000302619', null, null, true),
            new ProductUnit(4, 11, 'box', 12, 'SOAP-BOX-12', null, null, false, false, false, false),
        ]);
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $this->reviewer = new AuthContext(2, 'reviewer@test', User::ROLE_ADMIN);
        $this->counter = new AuthContext(5, 'counter@test', User::ROLE_WAREHOUSE_STAFF);
        $this->recounter = new AuthContext(6, 'recounter@test', User::ROLE_WAREHOUSE_STAFF);
        $this->otherStaff = new AuthContext(8, 'other@test', User::ROLE_WAREHOUSE_STAFF);
        $this->sales = new AuthContext(7, 'sales@test', User::ROLE_SALES);
        $this->countRepository = new InMemoryStockCountRepository($this->ledger, $this->stock, $this->products);
        $this->counts = $this->service();
    }

    private function service(?CountTolerance $tolerance = null): StockCountService
    {
        $users = new InMemoryUserRepository([
            new User(1, 'Admin', 'admin@test', 'x', User::ROLE_ADMIN, true),
            new User(2, 'Reviewer', 'reviewer@test', 'x', User::ROLE_ADMIN, true),
            new User(5, 'Counter', 'counter@test', 'x', User::ROLE_WAREHOUSE_STAFF, true),
            new User(6, 'Recounter', 'recounter@test', 'x', User::ROLE_WAREHOUSE_STAFF, true),
            new User(7, 'Sales', 'sales@test', 'x', User::ROLE_SALES, true),
            new User(8, 'Other', 'other@test', 'x', User::ROLE_WAREHOUSE_STAFF, true),
        ]);
        $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
        $warehouses->method('findById')->willReturnCallback(static fn (int $id): ?Warehouse => $id === 1 ? new Warehouse(1, 'Main', '', true) : null);
        $warehouses->method('all')->willReturn([new Warehouse(1, 'Main', '', true)]);

        return new StockCountService(
            $this->countRepository,
            $this->stocks,
            $this->stock,
            $this->audit,
            $this->products,
            $users,
            $warehouses,
            new InMemoryCategoryRepository([new Category(1, 'Susu', '', true), new Category(2, 'Sabun', '', true)]),
            new ProductUnitCatalog($this->products, $this->units),
            $tolerance ?? new CountTolerance(2, 0),
        );
    }

    // ---- Barcodes ---------------------------------------------------------------------------------------------------

    public function testBarcodeValidationRequiresTheAlphabetAndAValidEan13CheckDigit(): void
    {
        self::assertSame(self::EAN_PCS, BarcodeService::normalize(' ' . self::EAN_PCS . ' '));
        self::assertSame('SOAP-BOX-12', BarcodeService::normalize('SOAP-BOX-12'));
        foreach (['', 'abc', str_repeat('9', 65), '8991000202613', 'Ü-1234', '12 34'] as $invalid) {
            try {
                BarcodeService::normalize($invalid);
                self::fail('Accepted ' . $invalid);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(2, \App\Service\ProductUnitService::ean13CheckDigit(substr(self::EAN_PCS, 0, 12)));
    }

    public function testBarcodeLookupReturnsProductUnitFactorAndActiveFlagsForWarehouseRolesOnly(): void
    {
        $barcodes = new BarcodeService($this->units, $this->products);
        $karton = $barcodes->lookup($this->counter, self::EAN_KARTON);
        self::assertSame(['id' => 10, 'sku' => 'SKU-010', 'name' => 'Susu UHT', 'is_active' => true, 'base_unit' => 'pcs', 'tracks_expiry' => true], $karton['product']);
        self::assertSame(['code' => 'karton', 'factor_to_base' => 24, 'is_base' => false, 'is_active' => true], $karton['unit']);
        self::assertTrue($karton['usable']);
        $inactiveUnit = $barcodes->lookup($this->admin, 'SOAP-BOX-12');
        self::assertFalse($inactiveUnit['unit']['is_active']);
        self::assertFalse($inactiveUnit['usable']);
        foreach ([[$this->counter, '8991000202629', 404], [$this->sales, self::EAN_PCS, 403]] as [$actor, $code, $status]) {
            try {
                $barcodes->lookup($actor, $code);
                self::fail('Expected ' . $status);
            } catch (HttpException $exception) {
                self::assertSame($status, $exception->statusCode());
            }
        }
    }

    public function testBarcodeApiFollowsTheJsonConventions(): void
    {
        $barcodes = new BarcodeService($this->units, $this->products);
        $call = static function (?AuthContext $actor, string $code) use ($barcodes): array {
            $session = new SessionManager();
            if ($actor !== null) {
                $session->login($actor);
            }
            $response = (new BarcodeController($barcodes, new AuthGuard($session)))->show(new Request('GET', '/api/barcodes/' . $code, ['code' => $code], [], []));

            return [$response->statusCode(), json_decode($response->body(), true)];
        };
        self::assertSame(401, $call(null, self::EAN_PCS)[0]);
        self::assertSame(403, $call($this->sales, self::EAN_PCS)[0]);
        self::assertSame([404, ['error' => 'Barcode not found.']], $call($this->counter, '8991000202629'));
        [$status, $body] = $call($this->counter, '8991000202613');
        self::assertSame(422, $status);
        self::assertStringContainsString('check digit', $body['error']);
        [$status, $body] = $call($this->counter, self::EAN_KARTON);
        self::assertSame(200, $status);
        self::assertSame(24, $body['unit']['factor_to_base']);
    }

    // ---- Tolerance --------------------------------------------------------------------------------------------------

    public function testToleranceIsTheLargerOfUnitsAndPercentOfExpected(): void
    {
        $tolerance = new CountTolerance(2, 0);
        self::assertSame(2, $tolerance->allowance(100));
        self::assertFalse($tolerance->exceeds(-2, 100));
        self::assertTrue($tolerance->exceeds(3, 100));
        self::assertTrue($tolerance->exceeds(1, 10), '2 % of 10 rounds down to 0: any variance needs review');
        self::assertSame(5, (new CountTolerance(2, 5))->allowance(100));
        self::assertTrue($tolerance->exceeds(4, 0), 'a found lot always exceeds a zero allowance');
        self::assertSame(2, CountTolerance::fromConfig(250, -1)->percent, 'out-of-range configuration falls back to the defaults');
        $this->expectException(\InvalidArgumentException::class);
        new CountTolerance(101, 0);
    }

    // ---- Lifecycle --------------------------------------------------------------------------------------------------

    public function testVarianceUsesSnapshotPlusLedgerMovementsUntilTheCountAndPostsTheDeltaToTheCurrentLot(): void
    {
        [$lotA, $lotB] = $this->receiveLots();
        $id = $this->newCount();
        $this->counts->start($this->admin, $id);
        $lines = $this->lineIdsByLot($id);
        self::assertSame([$lotA->id(), $lotB->id()], array_keys($lines));

        // Stock moves after the snapshot and before the count: 10 issued FEFO from lot A (earliest expiry).
        $this->stocks->issue(1, [new StockMovement(10, 10)], 1, 'SO', 501);
        self::assertSame(90, $this->lot($lotA->id())->quantity());
        // The counter finds 88 in lot A (2 missing) and exactly 50 in lot B, entered in kartons + pieces.
        $this->counts->record($this->counter, $id, [$lines[$lotA->id()] => ['quantity' => '88', 'unit' => 'pcs'], $lines[$lotB->id()] => ['quantity' => '50', 'unit' => '']]);
        // Stock keeps moving after the count: 5 more are received into lot A before posting.
        $this->stocks->receive(1, [new StockMovement(10, 5, [new LotSplit('A-1', '2027-01-31', 5, '1000.00')])], 1, 'PO', 77);
        self::assertSame(95, $this->lot($lotA->id())->quantity());

        $row = $this->line($id, $lines[$lotA->id()]);
        self::assertSame(100, $row['snapshot_quantity']);
        self::assertSame(90, $row['expected_quantity'], 'expected = snapshot 100 + movements -10 between snapshot and count');
        self::assertSame(-2, $row['variance']);
        $this->counts->submit($this->counter, $id);
        $this->counts->approve($this->reviewer, $id, 'Checked');
        $this->counts->post($this->counter, $id);

        self::assertSame(93, $this->lot($lotA->id())->quantity(), 'current 95 + variance -2; never "set to counted" (88)');
        self::assertSame(50, $this->lot($lotB->id())->quantity());
        self::assertSame(143, $this->stock->quantity(10, 1));
        $countRows = array_values(array_filter($this->ledger->entries(), static fn ($entry): bool => $entry->referenceType() === 'STOCK_COUNT'));
        self::assertCount(1, $countRows, 'only lines with a variance write a ledger row');
        self::assertSame([-2, $lotA->id(), $id, 'Adjustment'], [$countRows[0]->delta(), $countRows[0]->lotId(), $countRows[0]->referenceId(), $countRows[0]->movementType()]);
        $posted = $this->line($id, $lines[$lotA->id()]);
        self::assertSame([-2, -2, $lotA->id(), 143, 0], [$posted['approved_variance'], $posted['posted_delta'], $posted['posted_lot_id'], $posted['pair_quantity_after'], $posted['pair_reserved_at_post']]);
        self::assertSame('Posted', $this->countRepository->find($id)['status']);
        self::assertContains('stock-counts.post', array_map(static fn (array $row): string => $row['action'], $this->audit->entries()));
        $this->counts->post($this->counter, $id); // a repeated posting is a no-op
        self::assertSame(93, $this->lot($lotA->id())->quantity());
    }

    public function testCountersCountBlindWhileReviewersSeeExpectedVarianceAndValue(): void
    {
        [$lotA] = $this->receiveLots();
        $id = $this->newCount();
        $this->counts->start($this->admin, $id);
        $lines = $this->lineIdsByLot($id);
        $this->counts->record($this->counter, $id, [$lines[$lotA->id()] => ['quantity' => '4', 'unit' => 'karton']]);

        $blind = $this->counts->show($this->counter, $id);
        self::assertTrue($blind['blind']);
        foreach ($blind['lines'] as $line) {
            self::assertArrayNotHasKey('expected', $line);
            self::assertArrayNotHasKey('snapshot', $line);
            self::assertArrayNotHasKey('variance', $line);
            self::assertArrayNotHasKey('value', $line);
        }
        self::assertTrue($blind['can']['count']);
        self::assertFalse($this->counts->show($this->otherStaff, $id)['can']['count'], 'unassigned staff cannot count');
        self::assertTrue($this->counts->show($this->otherStaff, $id)['blind']);

        $review = $this->counts->show($this->reviewer, $id);
        self::assertFalse($review['blind']);
        $line = array_values(array_filter($review['lines'], static fn (array $row): bool => $row['lot_id'] === $lotA->id()))[0];
        self::assertSame(-4, $line['variance'], '4 karton = 96 pcs counted, 100 expected');
        self::assertSame('1000.00', $line['unit_cost']);
        self::assertSame(-4000.0, $line['value']);
        self::assertTrue($line['exceeds']);
        self::assertSame(96, $this->line($id, $lines[$lotA->id()])['counted_quantity']);
        self::assertSame(['karton', 4], [$this->line($id, $lines[$lotA->id()])['counted_unit_code'], $this->line($id, $lines[$lotA->id()])['counted_unit_quantity']]);
    }

    public function testRecountAboveToleranceByADifferentCounterReplacesTheFirstCount(): void
    {
        [$lotA, $lotB] = $this->receiveLots();
        $id = $this->newCount([5, 6]);
        $this->counts->start($this->admin, $id);
        $lines = $this->lineIdsByLot($id);
        $this->counts->record($this->counter, $id, [$lines[$lotA->id()] => ['quantity' => '90', 'unit' => 'pcs'], $lines[$lotB->id()] => ['quantity' => '49', 'unit' => 'pcs']]);
        $this->counts->submit($this->counter, $id);

        $this->expectValidation(fn () => $this->counts->requestRecount($this->reviewer, $id, [$lines[$lotB->id()]], 'Check'), 'Within the recount tolerance');
        $this->counts->requestRecount($this->reviewer, $id, [$lines[$lotA->id()]], 'Ten missing: count the shelf again');
        self::assertSame('RecountRequested', $this->countRepository->find($id)['status']);
        $blind = $this->counts->show($this->recounter, $id);
        $flagged = array_values(array_filter($blind['lines'], static fn (array $row): bool => $row['flagged_now']))[0];
        self::assertNull($flagged['counted_display'], 'the recounter does not see the first count');
        self::assertTrue($flagged['countable']);
        $this->expectValidation(fn () => $this->counts->record($this->counter, $id, [$lines[$lotA->id()] => ['quantity' => '91', 'unit' => 'pcs']]), 'different counter');
        $this->expectValidation(fn () => $this->counts->record($this->recounter, $id, [$lines[$lotB->id()] => ['quantity' => '50', 'unit' => 'pcs']]), 'not flagged for recount');
        $this->expectValidation(fn () => $this->counts->submit($this->recounter, $id), 'Recount the flagged lines');

        $this->counts->record($this->recounter, $id, [$lines[$lotA->id()] => ['quantity' => '99', 'unit' => 'pcs']]);
        self::assertSame('Counting', $this->countRepository->find($id)['status']);
        $this->counts->submit($this->recounter, $id);
        $this->expectValidation(fn () => $this->counts->requestRecount($this->reviewer, $id, [$lines[$lotA->id()]], 'Again'), 'recounted once');
        $this->counts->approve($this->reviewer, $id);
        $this->counts->post($this->admin, $id);
        self::assertSame([99, 49], [$this->lot($lotA->id())->quantity(), $this->lot($lotB->id())->quantity()], 'the recount (99) replaced the first count (90)');
    }

    public function testRecountNeedsASecondCounterAndApprovalNeedsAnAdminWhoDidNotCount(): void
    {
        [$lotA] = $this->receiveLots();
        $id = $this->newCount([5, 1]);
        $this->counts->start($this->admin, $id);
        $lines = $this->lineIdsByLot($id);
        $this->counts->record($this->admin, $id, array_map(static fn (int $lotId): array => ['quantity' => '1', 'unit' => 'pcs'], array_flip($lines)));
        $this->counts->submit($this->admin, $id);
        $this->expectStatus(fn () => $this->counts->approve($this->admin, $id), 403);
        self::assertFalse($this->counts->show($this->admin, $id)['can']['approve']);
        $this->expectStatus(fn () => $this->counts->approve($this->counter, $id), 403);

        $solo = $this->newCount([5], [11]);
        $this->stocks->receive(1, [new StockMovement(11, 10, [new LotSplit('S-1', null, 10, '500.00')])], 1, 'PO', 2);
        $this->counts->start($this->admin, $solo);
        $soloLine = array_values($this->lineIdsByLot($solo))[0];
        $this->counts->record($this->counter, $solo, [$soloLine => ['quantity' => '5', 'unit' => 'pcs']]);
        $this->counts->submit($this->counter, $solo);
        $this->expectValidation(fn () => $this->counts->requestRecount($this->reviewer, $solo, [$soloLine], 'Check'), 'Assign another counter');
        $this->counts->requestRecount($this->reviewer, $solo, [$soloLine], 'Check', [6]);
        self::assertContains(6, $this->countRepository->find($solo)['counters']);
        $this->counts->approve($this->reviewer, $id);
        self::assertSame('Approved', $this->countRepository->find($id)['status']);
        self::assertGreaterThan(0, $lotA->id());
    }

    public function testPostingRefusesDeltasBelowTheReservationOrBelowZeroAndListsEveryConflict(): void
    {
        [$lotA, $lotB] = $this->receiveLots();
        $id = $this->newCount();
        $this->counts->start($this->admin, $id);
        $lines = $this->lineIdsByLot($id);
        $this->counts->record($this->counter, $id, [$lines[$lotA->id()] => ['quantity' => '80', 'unit' => 'pcs'], $lines[$lotB->id()] => ['quantity' => '40', 'unit' => 'pcs']]);
        $this->counts->submit($this->counter, $id);
        $this->counts->approve($this->reviewer, $id);
        // After approval an order reserves 140 of the 150 units: posting -30 would leave 120 < 140.
        $this->stocks->reserve(1, [new StockMovement(10, 140)], 1, 'SO', 900, static function (): void {}, 'sales-orders.approve');
        try {
            $this->counts->post($this->counter, $id);
            self::fail('Posting below the reservation must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('below the 140 unit(s) reserved', $exception->getMessage());
            self::assertStringContainsString('line #' . $lines[$lotA->id()], $exception->getMessage());
            self::assertStringContainsString('line #' . $lines[$lotB->id()], $exception->getMessage());
        }
        self::assertSame([100, 50, 'Approved'], [$this->lot($lotA->id())->quantity(), $this->lot($lotB->id())->quantity(), $this->countRepository->find($id)['status']], 'nothing changed');
        self::assertSame([], array_values(array_filter($this->ledger->entries(), static fn ($entry): bool => $entry->referenceType() === 'STOCK_COUNT')));

        // StockService lists a lot that a delta would drive below zero, next to the reservation conflict.
        $conflicts = null;
        try {
            $this->stocks->postCount(1, [new CountPosting(1, 10, $lotB->id(), null, -60, 'line #1 SKU-010 lot B-1'), new CountPosting(2, 10, $lotA->id(), null, -1, 'line #2 SKU-010 lot A-1')], 1, 99, static function (): void {});
        } catch (ValidationException $exception) {
            $conflicts = $exception->getMessage();
        }
        self::assertNotNull($conflicts);
        self::assertStringContainsString('line #1 SKU-010 lot B-1: variance -60 would make lot B-1 negative (it now holds 50)', $conflicts);
        self::assertStringContainsString('reserved', $conflicts);
    }

    public function testFoundLotsBecomeLotsAtPostingAndExistingEmptyLotsGetASnapshotFromTheLedger(): void
    {
        $this->stocks->receive(1, [new StockMovement(11, 4, [new LotSplit('S-EMPTY', null, 4, '450.00')])], 1, 'PO', 3);
        $this->stocks->receive(1, [new StockMovement(11, 10, [new LotSplit('S-1', null, 10, '450.00')])], 1, 'PO', 2);
        $emptyLot = array_values(array_filter($this->stock->lots(11, 1), static fn (StockLot $lot): bool => $lot->code() === 'S-EMPTY'))[0];
        $this->stocks->issue(1, [new StockMovement(11, 4)], 1, 'SO', 9); // FEFO (no expiry, first received): S-EMPTY is emptied
        self::assertSame(0, $this->lot($emptyLot->id())->quantity());
        $id = $this->newCount([5], [10, 11]);
        $this->counts->start($this->admin, $id);
        // An unknown lot of a tracked product needs an expiry date.
        $this->expectValidation(fn () => $this->counts->addFoundLot($this->counter, $id, ['product_id' => '10', 'lot_code' => 'NEW-1', 'quantity' => '3']), 'tracks expiry');
        $this->expectValidation(fn () => $this->counts->addFoundLot($this->counter, $id, ['product_id' => '12', 'lot_code' => 'X-1', 'quantity' => '3']), 'not in the scope');
        $newLine = $this->counts->addFoundLot($this->counter, $id, ['product_id' => '11', 'lot_code' => 'FOUND-7', 'quantity' => '6', 'unit_code' => 'pcs']);
        $this->expectValidation(fn () => $this->counts->addFoundLot($this->counter, $id, ['product_id' => '11', 'lot_code' => 'found-7', 'quantity' => '1']), 'already on the count sheet');
        self::assertSame(['Found', null, 0, 0, 6], [$this->line($id, $newLine)['origin'], $this->line($id, $newLine)['lot_id'], $this->line($id, $newLine)['snapshot_quantity'], $this->line($id, $newLine)['expected_quantity'], $this->line($id, $newLine)['variance']]);
        $lots = $this->stock->lots(11, 1);
        $emptyNow = array_values(array_filter($lots, static fn (StockLot $lot): bool => $lot->id() === $emptyLot->id()))[0];
        $existingFound = $this->counts->addFoundLot($this->counter, $id, ['product_id' => '11', 'lot_code' => $emptyNow->code(), 'quantity' => '2']);
        $line = $this->line($id, $existingFound);
        self::assertSame([$emptyNow->id(), $emptyNow->quantity(), $emptyNow->quantity()], [$line['lot_id'], $line['snapshot_quantity'], $line['expected_quantity']]);
        foreach ($this->countRepository->lines($id) as $row) {
            if ($row['counted_quantity'] === null) {
                $this->counts->record($this->counter, $id, [$row['id'] => ['quantity' => (string) $row['snapshot_quantity'], 'unit' => '']]);
            }
        }
        $this->counts->submit($this->counter, $id);
        $this->counts->approve($this->reviewer, $id);
        $this->counts->post($this->admin, $id);
        $created = array_values(array_filter($this->stock->lots(11, 1), static fn (StockLot $lot): bool => $lot->code() === 'FOUND-7'))[0];
        self::assertSame([6, '500.00', 'STOCK_COUNT', $id], [$created->quantity(), $created->unitCost(), $created->sourceType(), $created->sourceId()], 'found lot at the current purchase price per base');
        self::assertSame($created->id(), $this->line($id, $newLine)['posted_lot_id']);
    }

    public function testAuthorizationIsEnforcedInTheService(): void
    {
        $this->receiveLots();
        $this->expectStatus(fn () => $this->counts->create($this->counter, $this->input()), 403);
        $this->expectStatus(fn () => $this->counts->search($this->sales, []), 403);
        $this->expectValidation(fn () => $this->counts->create($this->admin, ['counter_ids' => [7]] + $this->input()), 'cannot count');
        $id = $this->newCount();
        $this->expectStatus(fn () => $this->counts->show($this->sales, $id), 403);
        $this->expectStatus(fn () => $this->counts->start($this->counter, $id), 403);
        $this->counts->start($this->admin, $id);
        $this->counts->start($this->admin, $id); // repeat is a no-op
        $line = array_values($this->lineIdsByLot($id))[0];
        $this->expectStatus(fn () => $this->counts->record($this->otherStaff, $id, [$line => ['quantity' => '1']]), 403);
        $this->expectStatus(fn () => $this->counts->record($this->sales, $id, [$line => ['quantity' => '1']]), 403);
        $this->expectStatus(fn () => $this->counts->cancel($this->counter, $id, 'No'), 403);
        $this->expectValidation(fn () => $this->counts->submit($this->counter, $id), 'still need a count');
        $this->counts->cancel($this->admin, $id, 'Wrong scope');
        self::assertSame('Cancelled', $this->countRepository->find($id)['status']);
        $this->expectValidation(fn () => $this->counts->record($this->counter, $id, [$line => ['quantity' => '1']]), 'not open for counting');
    }

    public function testTwoOpenCountsOfOneWarehouseCannotShareAProduct(): void
    {
        $this->receiveLots();
        $first = $this->newCount();
        $this->counts->start($this->admin, $first);
        $second = $this->newCount([5], [10, 11]);
        $this->expectStatus(fn () => $this->counts->start($this->admin, $second), 409);
        self::assertSame('Draft', $this->countRepository->find($second)['status']);
        $this->counts->cancel($this->admin, $first, 'Replaced');
        $this->counts->start($this->admin, $second);
        self::assertSame('Counting', $this->countRepository->find($second)['status']);
    }

    public function testControllerRendersBlindSheetAndRedirectsAfterActions(): void
    {
        [$lotA] = $this->receiveLots();
        $id = $this->newCount();
        $this->counts->start($this->admin, $id);
        $controller = function (AuthContext $actor): StockCountController {
            $session = new SessionManager();
            $session->login($actor);
            $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
            $warehouses->method('all')->willReturn([new Warehouse(1, 'Main', '', true)]);

            return new StockCountController($this->counts, new AuthGuard($session), $warehouses, new InMemoryCategoryRepository([]), $this->products);
        };
        $html = $controller($this->counter)->show(new Request('GET', '/stock-counts/show', ['id' => (string) $id], [], []))->body();
        self::assertStringContainsString('data-scan-count', $html);
        self::assertStringContainsString('Blind count', $html);
        self::assertStringNotContainsString('data-expected', $html);
        $lines = $this->lineIdsByLot($id);
        $response = $controller($this->counter)->count(new Request('POST', '/stock-counts/count', [], ['id' => (string) $id, 'counts' => [$lines[$lotA->id()] => ['quantity' => '100', 'unit' => 'pcs']]], []));
        self::assertSame(302, $response->statusCode());
        $error = $controller($this->counter)->count(new Request('POST', '/stock-counts/count', [], ['id' => (string) $id, 'counts' => [$lines[$lotA->id()] => ['quantity' => '-1', 'unit' => 'pcs']]], []));
        self::assertSame(422, $error->statusCode());
        $review = $controller($this->reviewer)->show(new Request('GET', '/stock-counts/show', ['id' => (string) $id], [], []))->body();
        self::assertStringContainsString('data-expected', $review);
        self::assertStringContainsString('data-count-review', $review);
    }

    // ---- Helpers ----------------------------------------------------------------------------------------------------

    /** Lot A 100 pcs (expires first), lot B 50 pcs of product 10 in warehouse 1. @return array{0: StockLot, 1: StockLot} */
    private function receiveLots(): array
    {
        $this->stocks->receive(1, [new StockMovement(10, 150, [new LotSplit('A-1', '2027-01-31', 100, '1000.00'), new LotSplit('B-1', '2027-06-30', 50, '1000.00')])], 1, 'PO', 1);
        $lots = $this->stock->lots(10, 1);
        usort($lots, static fn (StockLot $a, StockLot $b): int => $a->id() <=> $b->id());

        return [$lots[0], $lots[1]];
    }

    /** @param list<int> $counters @param list<int> $products */
    private function newCount(array $counters = [5], array $products = [10]): int
    {
        return $this->counts->create($this->admin, ['counter_ids' => array_map('strval', $counters), 'product_ids' => array_map('strval', $products)] + $this->input());
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['warehouse_id' => '1', 'scope' => 'Products', 'product_ids' => ['10'], 'counter_ids' => ['5'], 'reason' => 'Quarterly count'];
    }

    /** @return array<int, int> lot id => line id */
    private function lineIdsByLot(int $id): array
    {
        $map = [];
        foreach ($this->countRepository->lines($id) as $line) {
            $map[(int) $line['lot_id']] = (int) $line['id'];
        }
        ksort($map);

        return $map;
    }

    /** @return array<string, mixed> */
    private function line(int $countId, int $lineId): array
    {
        foreach ($this->countRepository->lines($countId) as $line) {
            if ((int) $line['id'] === $lineId) {
                return $line;
            }
        }
        self::fail('Line not found.');
    }

    private function lot(int $id): StockLot
    {
        return $this->stock->findLot($id) ?? throw new \RuntimeException('Lot missing.');
    }

    private function expectValidation(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('Expected a validation error: ' . $message);
        } catch (ValidationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function expectStatus(callable $call, int $status): void
    {
        try {
            $call();
            self::fail('Expected HTTP ' . $status);
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->statusCode());
        }
    }
}
