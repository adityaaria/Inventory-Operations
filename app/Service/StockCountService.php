<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StockLot;
use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\StockCountRepositoryInterface;
use App\Repository\Contract\StockRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthContext;
use App\Support\CountTolerance;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use App\Support\RequestOrigin;
use App\Support\UnitSet;
use App\Validation\LotInput;

/**
 * Stock count sessions (D-18, ADR-024; user decision, not trainer).
 *
 * Lifecycle: Draft → Counting (start: snapshot of every lot with stock in scope + ledger watermark) → Submitted →
 * (RecountRequested → Counting → Submitted) → Approved → Posted; Cancelled any time before posting.
 *
 * Variance with stock moving during the count: each count line stores the ledger watermark of its count (the highest
 * ledger id visible while the pair is locked), so expected = snapshot + Σ ledger rows of the lot with
 * snapshot watermark < id ≤ count watermark, and variance = counted − expected. Posting applies the approved variance as a
 * delta to the *current* lot quantity through StockService::postCount() (movements after the count are kept), never
 * "set to counted".
 *
 * Authorization (in this service): Admin creates, starts, requests recounts, approves (only an Admin who counted no line
 * of the count — independent approval as in ADR-009) and cancels; only the users assigned as counters (Admin or
 * WarehouseStaff accounts) count; Admin and WarehouseStaff post an approved count and see the list; Sales gets 403.
 * Blind count: assigned counters and WarehouseStaff never receive snapshot, expected or variance values while the count
 * can still be counted; reviewers (Admins who are not counters of the count) do.
 */
final class StockCountService
{
    public const STATUSES = ['Draft', 'Counting', 'Submitted', 'RecountRequested', 'Approved', 'Posted', 'Cancelled'];
    public const OPEN = ['Counting', 'Submitted', 'RecountRequested', 'Approved'];
    public const SCOPES = ['All', 'Category', 'Products'];
    public const MAX_PRODUCTS = 100;
    public const MAX_COUNTERS = 20;
    public const MAX_LINES_PER_SAVE = 2000;
    private const NOT_FOUND = 'Stock count not found.';
    private const BLIND_STATUSES = ['Counting', 'Submitted', 'RecountRequested'];

    public function __construct(
        private readonly StockCountRepositoryInterface $counts,
        private readonly StockService $stock,
        private readonly StockRepositoryInterface $stocks,
        private readonly AuditLogRepositoryInterface $audit,
        private readonly ProductRepositoryInterface $products,
        private readonly UserRepositoryInterface $users,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ProductUnitCatalog $units,
        private readonly CountTolerance $tolerance = new CountTolerance(),
    ) {
    }

    public function tolerance(): CountTolerance
    {
        return $this->tolerance;
    }

    /** Admin and WarehouseStaff work with counts; Sales gets 403. */
    public function authorize(AuthContext $actor): void
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function requireAdmin(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    // ---- Create and start -----------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input warehouse_id, scope, category_id, product_ids[], counter_ids[], reason */
    public function create(AuthContext $actor, array $input): int
    {
        $this->requireAdmin($actor);
        $warehouseId = self::positiveInt($input['warehouse_id'] ?? null, 'Choose a warehouse.');
        $warehouse = $this->warehouses->findById($warehouseId);
        if ($warehouse === null || !$warehouse->isActive()) {
            throw new ValidationException('Choose an active warehouse.');
        }
        $scope = is_string($input['scope'] ?? null) && in_array($input['scope'], self::SCOPES, true) ? $input['scope'] : throw new ValidationException('Choose the scope: all products, one category or selected products.');
        $categoryId = null;
        $productIds = [];
        if ($scope === 'Category') {
            $categoryId = self::positiveInt($input['category_id'] ?? null, 'Choose a category.');
            $category = $this->categories->findById($categoryId);
            if ($category === null || !$category->isActive()) {
                throw new ValidationException('Choose an active category.');
            }
        } elseif ($scope === 'Products') {
            $productIds = self::idList($input['product_ids'] ?? null, self::MAX_PRODUCTS, 'Select 1–' . self::MAX_PRODUCTS . ' products.');
            foreach ($productIds as $productId) {
                if (!($this->products->findById($productId)?->isActive() ?? false)) {
                    throw new ValidationException('Product #' . $productId . ' is not an active product.');
                }
            }
        }
        $counterIds = $this->counterIds($input['counter_ids'] ?? null, true);
        $reason = BusinessOperationInput::reason($input['reason'] ?? null);

        return $this->stock->transaction(function () use ($actor, $warehouseId, $scope, $categoryId, $productIds, $counterIds, $reason): int {
            $id = $this->counts->create($warehouseId, $scope, $categoryId, $productIds, $counterIds, $reason, $actor->userId());
            $this->audit->append($actor->userId(), 'stock-counts.create', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), [
                'warehouse_id' => $warehouseId, 'scope' => $scope, 'category_id' => $categoryId, 'product_ids' => $productIds, 'counter_ids' => $counterIds, 'reason' => $reason,
            ]);

            return $id;
        });
    }

    /**
     * Draft → Counting. Locks the count, then every pair of the scope in product order and their lots (ADR-023), checks
     * that no other open count of the warehouse shares a product, reads the ledger watermark and verifies every lot
     * against it (lot = opening + Σ ledger ≤ watermark), then stores one line per lot with stock. Repeating a start is a
     * no-op.
     */
    public function start(AuthContext $actor, int $id): void
    {
        $this->requireAdmin($actor);
        $count = $this->counts->find($id) ?? throw new HttpException(404, self::NOT_FOUND);
        if ($count['status'] === 'Counting') {
            return;
        }
        if ($count['status'] !== 'Draft') {
            throw new ValidationException('Only a draft count can be started.');
        }
        // Resolved before the transaction: no consistent read may fix the read view before the stock locks.
        $productIds = $this->counts->resolveScope((int) $count['warehouse_id'], (string) $count['scope'], $count['category_id'] === null ? null : (int) $count['category_id'], $count['product_ids']);
        if ($productIds === []) {
            throw new ValidationException('No active product is in the scope of this count.');
        }
        $warehouseId = (int) $count['warehouse_id'];
        $this->stock->transaction(function () use ($actor, $id, $warehouseId, $productIds): void {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            if ($locked['status'] === 'Counting') {
                return;
            }
            if ($locked['status'] !== 'Draft') {
                throw new ValidationException('Only a draft count can be started.');
            }
            $this->stock->lockForCount($warehouseId, $productIds, function (array $lots) use ($actor, $id, $warehouseId, $productIds): void {
                $overlap = $this->counts->overlapping($id, $warehouseId, $productIds);
                if ($overlap !== []) {
                    throw new HttpException(409, 'Count #' . implode(', #', $overlap) . ' of this warehouse is still open for some of these products. Post or cancel it first, so one discrepancy is never corrected twice.');
                }
                $watermark = $this->counts->ledgerWatermark();
                $all = array_merge(...array_values($lots));
                $this->assertLotsMatchLedger($all, $watermark);
                $lines = [];
                foreach ($all as $lot) {
                    if ($lot->quantity() > 0) {
                        $lines[] = ['product_id' => $lot->productId(), 'lot_id' => $lot->id(), 'lot_code' => $lot->code(), 'expiry_date' => $lot->expiryDate(), 'snapshot_quantity' => $lot->quantity()];
                    }
                }
                $this->counts->start($id, $actor->userId(), $watermark, $productIds, $lines);
                $this->audit->append($actor->userId(), 'stock-counts.start', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), [
                    'warehouse_id' => $warehouseId, 'products' => count($productIds), 'lines' => count($lines), 'snapshot_ledger_id' => $watermark,
                ]);
            });
        });
    }

    // ---- Counting -------------------------------------------------------------------------------------------------

    /**
     * Records counts (first count, or recount of the lines flagged in the current recount round). $entries maps a line id
     * to ['quantity' => whole number, 'unit' => unit code]; empty quantities are skipped. Inside one transaction the count
     * is locked, then the pairs of the lines and their lots; the ledger watermark is read under these locks and each line
     * stores expected = snapshot + Σ ledger (snapshot watermark, count watermark] (cross-checked against the locked lot).
     *
     * @param array<int|string, mixed> $entries
     */
    public function record(AuthContext $actor, int $id, array $entries): int
    {
        $this->authorize($actor);
        $count = $this->counts->find($id) ?? throw new HttpException(404, self::NOT_FOUND);
        $this->assertCounter($actor, $count);
        if (!in_array($count['status'], ['Counting', 'RecountRequested'], true)) {
            throw new ValidationException('This count is not open for counting.');
        }
        $lines = self::byId($this->counts->lines($id));
        $parsed = $this->parseEntries($entries, $lines);
        if ($parsed === []) {
            throw new ValidationException('Enter at least one counted quantity.');
        }

        return $this->stock->transaction(function () use ($actor, $id, $parsed, $lines): int {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            $this->assertCounter($actor, $locked);
            if (!in_array($locked['status'], ['Counting', 'RecountRequested'], true)) {
                throw new ValidationException('This count is not open for counting.');
            }
            $round = (int) $locked['recount_round'];
            $lockedLines = self::byId($this->counts->lines($id, true));
            foreach (array_keys($parsed) as $lineId) {
                $line = $lockedLines[$lineId] ?? throw new ValidationException('Line #' . $lineId . ' is not part of this count.');
                $this->assertCountable($actor, $locked, $line, $this->label($lines[$lineId] ?? $line));
            }
            $productIds = array_values(array_unique(array_map(static fn (int $lineId): int => (int) $lockedLines[$lineId]['product_id'], array_keys($parsed))));

            return $this->stock->lockForCount((int) $locked['warehouse_id'], $productIds, function (array $lots) use ($actor, $id, $locked, $round, $parsed, $lockedLines): int {
                $watermark = $this->counts->ledgerWatermark();
                $lotIds = [];
                foreach (array_keys($parsed) as $lineId) {
                    if ($lockedLines[$lineId]['lot_id'] !== null) {
                        $lotIds[] = (int) $lockedLines[$lineId]['lot_id'];
                    }
                }
                $movements = $lotIds === [] ? [] : $this->counts->lotMovements($lotIds, (int) $locked['snapshot_ledger_id'], $watermark);
                foreach ($parsed as $lineId => [$base, $unitCode, $unitQuantity]) {
                    $line = $lockedLines[$lineId];
                    $expected = $this->expectedNow($line, $lots, $movements);
                    if ($round === 0) {
                        $this->counts->recordCount($lineId, $base, $unitCode, $unitQuantity, $actor->userId(), $watermark, $expected);
                    } else {
                        $this->counts->recordRecount($lineId, $base, $unitCode, $unitQuantity, $actor->userId(), $watermark, $expected);
                    }
                }
                if ($locked['status'] === 'RecountRequested') {
                    $this->counts->resumeCounting($id);
                }
                $this->audit->append($actor->userId(), 'stock-counts.count', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), [
                    'phase' => $round === 0 ? 'count' : 'recount', 'round' => $round, 'lines' => count($parsed), 'ledger_watermark' => $watermark,
                ]);

                return count($parsed);
            });
        });
    }

    /**
     * A lot found during the first count that is not on the sheet: an existing lot of the pair without stock at the
     * snapshot (snapshot = its quantity at the snapshot watermark, worked back from the ledger), or an unknown lot that
     * posting creates (snapshot and expected 0; tracked products need an expiry date). The counted quantity is recorded
     * with the line.
     *
     * @param array<string, mixed> $input product_id, lot_code, expiry_date, quantity, unit_code
     */
    public function addFoundLot(AuthContext $actor, int $id, array $input): int
    {
        $this->authorize($actor);
        $count = $this->counts->find($id) ?? throw new HttpException(404, self::NOT_FOUND);
        $this->assertCounter($actor, $count);
        if ($count['status'] !== 'Counting' || (int) $count['recount_round'] > 0) {
            throw new ValidationException('Found lots are entered during the first count only.');
        }
        $productId = self::positiveInt($input['product_id'] ?? null, 'Choose the product of the found lot.');
        if (!in_array($productId, $count['product_ids'], true)) {
            throw new ValidationException('Product #' . $productId . ' is not in the scope of this count.');
        }
        $product = $this->products->findById($productId) ?? throw new ValidationException('Product #' . $productId . ' does not exist.');
        $code = LotInput::code($input['lot_code'] ?? null);
        $expiry = LotInput::expiry($input['expiry_date'] ?? null);
        if ($expiry === null && $product->tracksExpiry()) {
            throw new ValidationException($product->sku() . ' tracks expiry: enter the expiry date of the found lot.');
        }
        $unit = $this->units->forProductIds([$productId])[$productId]->resolve(is_string($input['unit_code'] ?? null) ? $input['unit_code'] : null, $product->sku());
        $unitQuantity = BusinessOperationInput::quantity($input['quantity'] ?? null);
        $base = UnitSet::toBase($unitQuantity, $unit->factor());
        $warehouseId = (int) $count['warehouse_id'];

        return $this->stock->transaction(function () use ($actor, $id, $warehouseId, $productId, $code, $expiry, $unit, $unitQuantity, $base): int {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            $this->assertCounter($actor, $locked);
            if ($locked['status'] !== 'Counting' || (int) $locked['recount_round'] > 0) {
                throw new ValidationException('Found lots are entered during the first count only.');
            }
            $lockedLines = $this->counts->lines($id, true);

            return $this->stock->lockForCount($warehouseId, [$productId], function (array $lots) use ($actor, $id, $locked, $productId, $code, $expiry, $unit, $unitQuantity, $base, $lockedLines): int {
                $watermark = $this->counts->ledgerWatermark();
                $match = null;
                $candidates = $lots[$productId];
                usort($candidates, static fn (StockLot $a, StockLot $b): int => $a->sequence() <=> $b->sequence());
                foreach ($candidates as $lot) {
                    if (strcasecmp($lot->code(), $code) === 0 && $lot->expiryDate() === $expiry) {
                        $match = $lot;
                        break;
                    }
                }
                foreach ($lockedLines as $line) {
                    $same = $match !== null ? (int) ($line['lot_id'] ?? 0) === $match->id()
                        : $line['lot_id'] === null && (int) $line['product_id'] === $productId && strcasecmp((string) $line['lot_code'], $code) === 0 && ($line['expiry_date'] ?? null) === $expiry;
                    if ($same) {
                        throw new ValidationException('Lot ' . $code . ' is already on the count sheet (line #' . $line['id'] . '); count it there.');
                    }
                }
                if ($match !== null) {
                    $movement = $this->counts->lotMovements([$match->id()], (int) $locked['snapshot_ledger_id'], $watermark)[$match->id()] ?? 0;
                    $lineId = $this->counts->addFoundLine($id, ['product_id' => $productId, 'lot_id' => $match->id(), 'lot_code' => $match->code(), 'expiry_date' => $match->expiryDate(), 'snapshot_quantity' => max(0, $match->quantity() - $movement)]);
                    $expected = $match->quantity();
                } else {
                    $lineId = $this->counts->addFoundLine($id, ['product_id' => $productId, 'lot_id' => null, 'lot_code' => $code, 'expiry_date' => $expiry, 'snapshot_quantity' => 0]);
                    $expected = 0;
                }
                $this->counts->recordCount($lineId, $base, $unit->code(), $unitQuantity, $actor->userId(), $watermark, $expected);
                $this->audit->append($actor->userId(), 'stock-counts.found-lot', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), [
                    'line_id' => $lineId, 'product_id' => $productId, 'lot_code' => $code, 'expiry_date' => $expiry, 'existing_lot_id' => $match?->id(), 'counted' => $base,
                ]);

                return $lineId;
            });
        });
    }

    /** Counting → Submitted (an assigned counter or an Admin). Every line must be counted (in a recount round: every flagged line recounted). */
    public function submit(AuthContext $actor, int $id): void
    {
        $this->authorize($actor);
        $display = self::byId($this->counts->lines($id));
        $this->stock->transaction(function () use ($actor, $id, $display): void {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            if ($actor->role() !== User::ROLE_ADMIN) {
                $this->assertCounter($actor, $locked);
            }
            if ($locked['status'] !== 'Counting') {
                throw new ValidationException($locked['status'] === 'RecountRequested' ? 'Recount the flagged lines before submitting.' : 'Only a count in progress can be submitted.');
            }
            $round = (int) $locked['recount_round'];
            $lines = $this->counts->lines($id, true);
            if ($lines === []) {
                throw new ValidationException('Nothing to submit: no lot of this scope has stock. Enter found lots or cancel the count.');
            }
            $missing = [];
            foreach ($lines as $line) {
                $open = $round === 0 ? $line['counted_quantity'] === null : ((int) $line['recount_requested'] === $round && $line['recounted_quantity'] === null);
                if ($open) {
                    $missing[] = $this->label($display[(int) $line['id']] ?? $line);
                }
            }
            if ($missing !== []) {
                throw new ValidationException(count($missing) . ' line(s) still need a ' . ($round === 0 ? 'count' : 'recount') . ': ' . implode('; ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? '; …' : '') . '.');
            }
            $this->counts->submitted($id, $actor->userId());
            $this->audit->append($actor->userId(), 'stock-counts.submit', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), ['round' => $round, 'lines' => count($lines)]);
        });
    }

    // ---- Review -----------------------------------------------------------------------------------------------------

    /**
     * Submitted → RecountRequested (Admin). Only lines whose variance exceeds the tolerance and that were not recounted
     * before may be flagged. A recount must come from another counter than the line's first count (enforced; the DB
     * CHECK chk_stock_count_lines_recounter backs it), so at least one assigned counter — optionally added here — must
     * differ from each flagged line's counter.
     *
     * @param list<mixed>|mixed $lineIds
     * @param list<mixed>|mixed $addCounterIds
     */
    public function requestRecount(AuthContext $actor, int $id, mixed $lineIds, mixed $reason, mixed $addCounterIds = []): void
    {
        $this->requireAdmin($actor);
        $lineIds = self::idList($lineIds, self::MAX_LINES_PER_SAVE, 'Select the lines to recount.');
        $reason = BusinessOperationInput::reason($reason);
        $add = $addCounterIds === [] || $addCounterIds === null || $addCounterIds === '' ? [] : $this->counterIds($addCounterIds, false);
        $display = self::byId($this->counts->lines($id));
        $this->stock->transaction(function () use ($actor, $id, $lineIds, $reason, $add, $display): void {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            if ($locked['status'] !== 'Submitted') {
                throw new ValidationException('Recounts are requested for a submitted count.');
            }
            $lines = self::byId($this->counts->lines($id, true));
            $counters = array_values(array_unique([...$locked['counters'], ...$add]));
            $within = [];
            foreach ($lineIds as $lineId) {
                $line = $lines[$lineId] ?? throw new ValidationException('Line #' . $lineId . ' is not part of this count.');
                $label = $this->label($display[$lineId] ?? $line);
                if ((int) $line['recount_requested'] > 0) {
                    throw new ValidationException($label . ' was already recounted; a line is recounted once.');
                }
                $variance = (int) $line['counted_quantity'] - (int) $line['expected_quantity'];
                if (!$this->tolerance->exceeds($variance, (int) $line['expected_quantity'])) {
                    $within[] = $label;
                }
                if (array_diff($counters, [(int) $line['counted_by']]) === []) {
                    throw new ValidationException('Assign another counter for ' . $label . ': a recount is made by a different person than the first count.');
                }
            }
            if ($within !== []) {
                throw new ValidationException('Within the recount tolerance (' . $this->tolerance->percent . ' % / ' . $this->tolerance->units . ' unit(s)); no recount needed: ' . implode('; ', $within) . '.');
            }
            $this->counts->recountRequested($id, $actor->userId(), $reason, $lineIds, $add);
            $this->audit->append($actor->userId(), 'stock-counts.recount', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), [
                'round' => (int) $locked['recount_round'] + 1, 'line_ids' => $lineIds, 'added_counter_ids' => $add, 'reason' => $reason,
            ]);
        });
    }

    /**
     * Submitted → Approved (Admin who counted no line of this count; independent approval as ADR-009). Freezes every line's
     * variance as its approved variance.
     */
    public function approve(AuthContext $actor, int $id, mixed $note = null): void
    {
        $this->requireAdmin($actor);
        $note = is_string($note) && trim($note) !== '' ? BusinessOperationInput::reason($note) : null;
        $this->stock->transaction(function () use ($actor, $id, $note): void {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            if ($locked['status'] !== 'Submitted') {
                throw new ValidationException('Only a submitted count can be approved.');
            }
            $lines = $this->counts->lines($id, true);
            foreach ($lines as $line) {
                if ((int) $line['counted_by'] === $actor->userId() || (int) ($line['recounted_by'] ?? 0) === $actor->userId()) {
                    throw new HttpException(403, 'You counted lines of this count; another Admin must approve it (independent approval).');
                }
            }
            $this->counts->approved($id, $actor->userId(), $note);
            $variances = array_map(static fn (array $line): int => (int) ($line['recounted_quantity'] ?? $line['counted_quantity']) - (int) ($line['recount_expected_quantity'] ?? $line['expected_quantity']), $lines);
            $this->audit->append($actor->userId(), 'stock-counts.approve', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), [
                'lines' => count($lines), 'variance_lines' => count(array_filter($variances)), 'net_units' => array_sum($variances), 'note' => $note,
            ]);
        });
    }

    /**
     * Approved → Posted (Admin or WarehouseStaff). Repeating a posting is a no-op. StockService locks the pairs and lots,
     * this service re-verifies every line against the ledger (expected unchanged; no other count corrected the lot after
     * it was counted, else 409) and StockService refuses with 422 listing every conflicting line, or applies the deltas.
     */
    public function post(AuthContext $actor, int $id): void
    {
        $this->authorize($actor);
        $count = $this->counts->find($id) ?? throw new HttpException(404, self::NOT_FOUND);
        if ($count['status'] === 'Posted') {
            return;
        }
        if ($count['status'] !== 'Approved') {
            throw new ValidationException('Only an approved count can be posted.');
        }
        $display = self::byId($this->counts->lines($id));
        $this->stock->transaction(function () use ($actor, $id, $display): void {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            if ($locked['status'] === 'Posted') {
                return;
            }
            if ($locked['status'] !== 'Approved') {
                throw new ValidationException('Only an approved count can be posted.');
            }
            $lines = $this->counts->lines($id, true);
            $postings = [];
            foreach ($lines as $line) {
                $variance = $line['approved_variance'] ?? throw new \LogicException('Approved count line #' . $line['id'] . ' has no approved variance.');
                $postings[] = new CountPosting(
                    (int) $line['id'],
                    (int) $line['product_id'],
                    $line['lot_id'] === null ? null : (int) $line['lot_id'],
                    $line['lot_id'] === null ? new LotSplit((string) $line['lot_code'], $line['expiry_date'] === null ? null : (string) $line['expiry_date'], max(1, (int) $variance)) : null,
                    (int) $variance,
                    $this->label($display[(int) $line['id']] ?? $line),
                );
            }
            $this->stock->postCount(
                (int) $locked['warehouse_id'],
                $postings,
                $actor->userId(),
                $id,
                fn (array $posted) => $this->counts->posted($id, $actor->userId(), $posted),
                fn (array $lots) => $this->verifyBeforePosting($id, $locked, $lines, $display),
            );
        });
    }

    public function cancel(AuthContext $actor, int $id, mixed $reason): void
    {
        $this->requireAdmin($actor);
        $reason = BusinessOperationInput::reason($reason);
        $this->stock->transaction(function () use ($actor, $id, $reason): void {
            $locked = $this->counts->find($id, true) ?? throw new HttpException(404, self::NOT_FOUND);
            if ($locked['status'] === 'Cancelled') {
                return;
            }
            if ($locked['status'] === 'Posted') {
                throw new ValidationException('A posted count cannot be cancelled; correct it with a new count or adjustment.');
            }
            $this->counts->cancelled($id, $actor->userId(), $reason);
            $this->audit->append($actor->userId(), 'stock-counts.cancel', 'STOCK_COUNT', $id, 'success', RequestOrigin::none(), ['previous_status' => $locked['status'], 'reason' => $reason]);
        });
    }

    // ---- Read models ------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input @return PaginatedResult<array<string, mixed>> */
    public function search(AuthContext $actor, array $input): PaginatedResult
    {
        $this->authorize($actor);
        $filters = $this->listFilters($input);
        if (($input['mine'] ?? '') === '1') {
            $filters['counter_id'] = $actor->userId();
        }
        $total = $this->counts->searchCount($filters);
        $pagination = Pagination::fromArray($input);

        return new PaginatedResult($this->counts->search($filters, Pagination::PER_PAGE, $pagination->offsetForTotal($total)), $total, $pagination->pageForTotal($total), Pagination::PER_PAGE);
    }

    /** @param array<string, mixed> $input @return array{status?: string, warehouse_id?: int} */
    public function listFilters(array $input): array
    {
        $filters = [];
        $status = $input['status'] ?? '';
        if ($status !== '' && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            throw new ValidationException('Invalid status filter.');
        }
        if ($status !== '') {
            $filters['status'] = $status;
        }
        $warehouse = $input['warehouse_id'] ?? '';
        if ($warehouse !== '') {
            $filters['warehouse_id'] = self::positiveInt($warehouse, 'Invalid warehouse filter.');
        }

        return $filters;
    }

    /**
     * Detail, counting and review model. Blind (no snapshot, expected, variance or value) for everyone but reviewers while
     * the count can still be counted; a line flagged for recount also hides its first count then.
     *
     * @return array<string, mixed>
     */
    public function show(AuthContext $actor, int $id): array
    {
        $this->authorize($actor);
        $count = $this->counts->find($id) ?? throw new HttpException(404, self::NOT_FOUND);
        $lines = $this->counts->lines($id);
        $isCounter = in_array($actor->userId(), $count['counters'], true);
        $reviewer = $actor->role() === User::ROLE_ADMIN && !$isCounter;
        $blind = !$reviewer && in_array($count['status'], self::BLIND_STATUSES, true);
        $round = (int) $count['recount_round'];
        $productIds = array_values(array_unique([...array_map(static fn (array $line): int => (int) $line['product_id'], $lines), ...$count['product_ids']]));
        $unitSets = $productIds === [] ? [] : $this->units->forProductIds($productIds);
        $countedBy = [];
        $summary = ['lines' => count($lines), 'counted' => 0, 'variance_lines' => 0, 'over_tolerance' => 0, 'net_units' => 0, 'value' => 0.0, 'gain_value' => 0.0, 'loss_value' => 0.0];
        $rows = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $units = $unitSets[$productId] ?? UnitSet::baseOnly((string) ($line['base_unit'] ?? 'unit'));
            $counted = $line['recounted_quantity'] ?? $line['counted_quantity'];
            $expected = $line['recount_expected_quantity'] ?? $line['expected_quantity'];
            $variance = $counted === null || $expected === null ? null : (int) $counted - (int) $expected;
            $cost = $line['posted_unit_cost'] ?? $line['lot_unit_cost'] ?? ($line['lot_id'] === null ? $this->stocks->purchaseCost($productId) : null);
            $flaggedNow = $round > 0 && (int) $line['recount_requested'] === $round;
            $row = [
                'id' => (int) $line['id'],
                'product_id' => $productId,
                'label' => $this->label($line),
                'sku' => (string) ($line['sku'] ?? ''),
                'product_name' => (string) ($line['product_name'] ?? ''),
                'lot_id' => $line['lot_id'] === null ? null : (int) $line['lot_id'],
                'posted_lot_id' => $line['posted_lot_id'] === null ? null : (int) $line['posted_lot_id'],
                'lot_code' => (string) $line['lot_code'],
                'expiry_date' => $line['expiry_date'],
                'origin' => (string) $line['origin'],
                'new_lot' => $line['lot_id'] === null,
                'units' => $units,
                'recount_requested' => (int) $line['recount_requested'],
                'flagged_now' => $flaggedNow,
                'counted_display' => $line['counted_quantity'] === null || ($blind && $flaggedNow) ? null : $units->format((int) $line['counted_quantity']),
                'counted_by' => $line['counted_by'] === null ? null : (int) $line['counted_by'],
                'counted_by_name' => $line['counted_by'] === null ? null : ($line['counted_by_name'] ?? $this->userName((int) $line['counted_by'])),
                'counted_at' => $line['counted_at'],
                'recounted_display' => $line['recounted_quantity'] === null ? null : $units->format((int) $line['recounted_quantity']),
                'recounted_by_name' => $line['recounted_by'] === null ? null : ($line['recounted_by_name'] ?? $this->userName((int) $line['recounted_by'])),
                'countable' => $this->countable($actor, $count, $line),
            ];
            if ($line['counted_by'] !== null) {
                $countedBy[(int) $line['counted_by']] = true;
            }
            if ($line['recounted_by'] !== null) {
                $countedBy[(int) $line['recounted_by']] = true;
            }
            if ($counted !== null) {
                $summary['counted']++;
            }
            if (!$blind) {
                $value = $variance === null || $cost === null ? null : round($variance * (float) $cost, 2);
                $exceeds = $variance !== null && $this->tolerance->exceeds($variance, (int) $expected);
                $row += [
                    'snapshot' => $units->format((int) $line['snapshot_quantity']),
                    'expected' => $expected === null ? null : $units->format((int) $expected),
                    'first_expected' => $line['expected_quantity'] === null ? null : $units->format((int) $line['expected_quantity']),
                    'variance' => $variance,
                    'variance_display' => $variance === null ? null : ($variance > 0 ? '+' : '') . $units->format($variance),
                    'first_variance' => $line['counted_quantity'] === null ? null : (int) $line['counted_quantity'] - (int) $line['expected_quantity'],
                    'unit_cost' => $cost,
                    'value' => $value,
                    'exceeds' => $exceeds,
                    'allowance' => $expected === null ? null : $this->tolerance->allowance((int) $expected),
                    'recountable' => $count['status'] === 'Submitted' && $exceeds && (int) $line['recount_requested'] === 0,
                    'posted_delta' => $line['posted_delta'] === null ? null : (int) $line['posted_delta'],
                ];
                if ($variance !== null && $variance !== 0) {
                    $summary['variance_lines']++;
                    $summary['net_units'] += $variance;
                    $summary['value'] += $value ?? 0.0;
                    if (($value ?? 0.0) > 0) {
                        $summary['gain_value'] += (float) $value;
                    } else {
                        $summary['loss_value'] += (float) $value;
                    }
                }
                if ($exceeds) {
                    $summary['over_tolerance']++;
                }
            }
            $rows[] = $row;
        }
        $status = (string) $count['status'];
        $admin = $actor->role() === User::ROLE_ADMIN;
        $scopeProducts = [];
        if ($isCounter && $status === 'Counting' && $round === 0) {
            foreach ($count['product_ids'] as $productId) {
                $product = $this->products->findById((int) $productId);
                if ($product !== null) {
                    $scopeProducts[] = ['product' => $product, 'units' => $unitSets[(int) $productId] ?? UnitSet::forProduct($product)];
                }
            }
        }

        return [
            'count' => $count,
            'lines' => $rows,
            'blind' => $blind,
            'reviewer' => $reviewer,
            'is_counter' => $isCounter,
            'round' => $round,
            'summary' => $summary,
            'tolerance' => $this->tolerance,
            'counter_names' => array_map(fn (int $userId): string => $this->userName($userId), $count['counters']),
            'scope_products' => $scopeProducts,
            'can' => [
                'start' => $admin && $status === 'Draft',
                'count' => $isCounter && in_array($status, ['Counting', 'RecountRequested'], true),
                'found' => $isCounter && $status === 'Counting' && $round === 0,
                'submit' => $status === 'Counting' && ($isCounter || $admin),
                'recount' => $reviewer && $status === 'Submitted',
                'approve' => $admin && $status === 'Submitted' && !isset($countedBy[$actor->userId()]),
                'post' => $status === 'Approved',
                'cancel' => $admin && !in_array($status, ['Posted', 'Cancelled'], true),
            ],
            'counters_available' => $admin && $status === 'Submitted' ? $this->eligibleCounters() : [],
        ];
    }

    /** Users who may be assigned as counters (active Admin and WarehouseStaff accounts). @return list<User> */
    public function eligibleCounters(): array
    {
        return array_values(array_filter($this->users->all(), static fn (User $user): bool => $user->isActive() && in_array($user->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)));
    }

    /**
     * Count history and variance summary of posted counts (quantity and value).
     *
     * @param array<string, mixed> $input warehouse_id, from, to
     * @return array{filters: array{warehouse_id: int, from: string, to: string}, history: list<array<string, mixed>>, lines: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function report(AuthContext $actor, array $input): array
    {
        $this->authorize($actor);
        $filters = $this->reportFilters($input);
        $history = $this->counts->history($filters);
        $lines = $this->counts->postedLines($filters);
        $totals = ['counts' => count($history), 'lines' => 0, 'variance_lines' => 0, 'recounted_lines' => 0, 'found_lots' => 0, 'units_gained' => 0, 'units_lost' => 0, 'net_units' => 0, 'net_value' => 0.0, 'absolute_value' => 0.0];
        foreach ($history as $row) {
            $totals['lines'] += (int) $row['line_count'];
            $totals['variance_lines'] += (int) $row['variance_lines'];
            $totals['recounted_lines'] += (int) $row['recounted_lines'];
            $totals['found_lots'] += (int) $row['found_lots'];
            $totals['units_gained'] += (int) $row['units_gained'];
            $totals['units_lost'] += (int) $row['units_lost'];
            $totals['net_units'] += (int) $row['net_units'];
            $totals['net_value'] += (float) $row['net_value'];
            $totals['absolute_value'] += (float) $row['absolute_value'];
        }

        return ['filters' => $filters, 'history' => $history, 'lines' => $lines, 'totals' => $totals];
    }

    private const CSV_COLUMNS = ['Count', 'Posted at', 'Warehouse', 'SKU', 'Product', 'Lot', 'Expiry', 'Origin', 'Snapshot', 'Expected', 'Counted', 'Recounted', 'Variance', 'Base unit', 'Unit cost', 'Value'];

    /** CSV of every posted line with a variance (formula-safe cells). @param array<string, mixed> $input */
    public function reportCsv(AuthContext $actor, array $input): \Closure
    {
        $lines = $this->report($actor, $input)['lines'];

        return static function () use ($lines): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }
            try {
                fputcsv($handle, self::CSV_COLUMNS, ',', '"', '\\');
                foreach ($lines as $row) {
                    $value = round((int) $row['variance'] * (float) $row['unit_cost'], 2);
                    fputcsv($handle, array_map(static fn (mixed $cell): string => self::csvValue($cell), [
                        $row['count_id'], $row['posted_at'], $row['warehouse_name'], $row['sku'], $row['product_name'], $row['lot_code'], $row['expiry_date'] ?? '', $row['origin'],
                        $row['snapshot_quantity'], $row['expected_quantity'], $row['counted_quantity'], (int) $row['recounted'] === 1 ? 'yes' : 'no', $row['variance'], $row['base_unit'],
                        $row['unit_cost'], number_format($value, 2, '.', ''),
                    ]), ',', '"', '\\');
                }
            } finally {
                fclose($handle);
            }
        };
    }

    /** @param array<string, mixed> $input @return array{warehouse_id: int, from: string, to: string} */
    private function reportFilters(array $input): array
    {
        $warehouse = $input['warehouse_id'] ?? '';
        $from = is_string($input['from'] ?? null) ? trim($input['from']) : '';
        $to = is_string($input['to'] ?? null) ? trim($input['to']) : '';
        foreach (['from' => $from, 'to' => $to] as $value) {
            if ($value !== '' && !\App\Support\BusinessDate::isValidDate($value)) {
                throw new ValidationException('Dates must be valid dates (YYYY-MM-DD).');
            }
        }

        return ['warehouse_id' => $warehouse === '' ? 0 : self::positiveInt($warehouse, 'Invalid warehouse filter.'), 'from' => $from, 'to' => $to];
    }

    // ---- Helpers ----------------------------------------------------------------------------------------------------

    /**
     * Posting guard, run after StockService locked the pairs: every line's stored expected value still equals snapshot +
     * Σ ledger (snapshot watermark, count watermark] (the ledger is append-only, so a difference is an invariant breach),
     * and no other count posted a correction on the lot after this line was counted — that correction would already
     * contain the same discrepancy, so applying this variance too would count it twice (409).
     *
     * @param array<string, mixed> $count
     * @param list<array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $display
     */
    private function verifyBeforePosting(int $id, array $count, array $lines, array $display): void
    {
        $snapshot = (int) $count['snapshot_ledger_id'];
        $after = [];
        foreach ($lines as $line) {
            if ($line['lot_id'] === null) {
                continue;
            }
            $lotId = (int) $line['lot_id'];
            foreach ([['count_ledger_id', 'expected_quantity'], ['recount_ledger_id', 'recount_expected_quantity']] as [$watermarkColumn, $expectedColumn]) {
                if ($line[$watermarkColumn] === null) {
                    continue;
                }
                $movement = $this->counts->lotMovements([$lotId], $snapshot, (int) $line[$watermarkColumn])[$lotId] ?? 0;
                if ((int) $line['snapshot_quantity'] + $movement !== (int) $line[$expectedColumn]) {
                    throw new \LogicException('Count line #' . $line['id'] . ' no longer matches the ledger (expected ≠ snapshot + movements); nothing was posted.');
                }
            }
            $after[$lotId] = (int) ($line['recount_ledger_id'] ?? $line['count_ledger_id']);
        }
        $later = $after === [] ? [] : $this->counts->laterCountCorrections($id, $after);
        if ($later !== []) {
            $named = [];
            foreach ($lines as $line) {
                if ($line['lot_id'] !== null && isset($later[(int) $line['lot_id']])) {
                    $named[] = $this->label($display[(int) $line['id']] ?? $line);
                }
            }
            throw new HttpException(409, 'Another count corrected these lots after they were counted here; posting would correct the same difference twice. Cancel this count and count again: ' . implode('; ', $named) . '.');
        }
    }

    /**
     * Expected quantity at count time = snapshot + Σ ledger movements since the snapshot watermark; cross-checked with the
     * locked lot (lot = opening + ledger, ADR-023). A new found lot (no lot yet) expects 0.
     *
     * @param array<string, mixed> $line
     * @param array<int, list<StockLot>> $lots
     * @param array<int, int> $movements
     */
    private function expectedNow(array $line, array $lots, array $movements): int
    {
        if ($line['lot_id'] === null) {
            return 0;
        }
        $lotId = (int) $line['lot_id'];
        $expected = (int) $line['snapshot_quantity'] + ($movements[$lotId] ?? 0);
        foreach ($lots[(int) $line['product_id']] ?? [] as $lot) {
            if ($lot->id() === $lotId) {
                if ($lot->quantity() !== $expected) {
                    throw new \LogicException('Lot #' . $lotId . ' does not match snapshot + ledger movements; nothing was recorded.');
                }

                return $expected;
            }
        }
        throw new \LogicException('Lot #' . $lotId . ' is not locked for the count.');
    }

    /** @param list<StockLot> $lots */
    private function assertLotsMatchLedger(array $lots, int $watermark): void
    {
        if ($lots === []) {
            return;
        }
        $balances = $this->counts->lotBalances(array_map(static fn (StockLot $lot): int => $lot->id(), $lots), $watermark);
        foreach ($lots as $lot) {
            if (($balances[$lot->id()] ?? 0) !== $lot->quantity()) {
                throw new \LogicException('Lot #' . $lot->id() . ' does not match its ledger at the snapshot watermark; the count was not started.');
            }
        }
    }

    /**
     * @param array<int|string, mixed> $entries
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array{0: int, 1: string, 2: int}> line id => [base quantity, unit code, unit quantity]
     */
    private function parseEntries(array $entries, array $lines): array
    {
        if (count($entries) > self::MAX_LINES_PER_SAVE) {
            throw new ValidationException('Save at most ' . self::MAX_LINES_PER_SAVE . ' lines at once.');
        }
        $productIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['product_id'], $lines)));
        $unitSets = $productIds === [] ? [] : $this->units->forProductIds($productIds);
        $parsed = [];
        foreach ($entries as $lineId => $entry) {
            $lineId = filter_var($lineId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($lineId) || !is_array($entry)) {
                throw new ValidationException('Invalid count line.');
            }
            $quantity = $entry['quantity'] ?? '';
            if ($quantity === '') {
                continue;
            }
            $line = $lines[$lineId] ?? throw new ValidationException('Line #' . $lineId . ' is not part of this count.');
            $units = $unitSets[(int) $line['product_id']] ?? UnitSet::baseOnly((string) ($line['base_unit'] ?? 'unit'));
            $unit = $units->resolve(is_string($entry['unit'] ?? null) ? $entry['unit'] : null, (string) ($line['sku'] ?? ('#' . $line['product_id'])));
            $unitQuantity = BusinessOperationInput::quantity($quantity, true);
            $parsed[$lineId] = [UnitSet::toBase($unitQuantity, $unit->factor()), $unit->code(), $unitQuantity];
        }

        return $parsed;
    }

    /** @param array<string, mixed> $count @param array<string, mixed> $line */
    private function assertCountable(AuthContext $actor, array $count, array $line, string $label): void
    {
        $round = (int) $count['recount_round'];
        if ($round === 0) {
            if ($count['status'] !== 'Counting') {
                throw new ValidationException('This count is not open for counting.');
            }

            return;
        }
        if ((int) $line['recount_requested'] !== $round) {
            throw new ValidationException($label . ' is not flagged for recount; only flagged lines are counted again.');
        }
        if ((int) $line['counted_by'] === $actor->userId()) {
            throw new ValidationException($label . ': a recount must be made by a different counter than the first count.');
        }
    }

    /** @param array<string, mixed> $count @param array<string, mixed> $line */
    private function countable(AuthContext $actor, array $count, array $line): bool
    {
        if (!in_array($actor->userId(), $count['counters'], true) || !in_array($count['status'], ['Counting', 'RecountRequested'], true)) {
            return false;
        }
        try {
            $this->assertCountable($actor, $count, $line, '');
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $count */
    private function assertCounter(AuthContext $actor, array $count): void
    {
        if (!in_array($actor->userId(), $count['counters'], true)) {
            throw new HttpException(403, 'Only the users assigned to count #' . $count['id'] . ' may count it.');
        }
    }

    /** @return list<int> */
    private function counterIds(mixed $value, bool $required): array
    {
        $ids = self::idList($value, self::MAX_COUNTERS, 'Assign 1–' . self::MAX_COUNTERS . ' counters.', !$required);
        foreach ($ids as $userId) {
            $user = $this->users->findById($userId);
            if ($user === null || !$user->isActive() || !in_array($user->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)) {
                throw new ValidationException('User #' . $userId . ' cannot count: counters are active Admin or Warehouse Staff users.');
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $line */
    private function label(array $line): string
    {
        $sku = (string) ($line['sku'] ?? '');
        if ($sku === '') {
            $sku = $this->products->findById((int) $line['product_id'])?->sku() ?? ('product #' . $line['product_id']);
        }

        return 'line #' . $line['id'] . ' ' . $sku . ' lot ' . $line['lot_code'];
    }

    /** @var array<int, string> */
    private array $userNames = [];

    private function userName(int $userId): string
    {
        return $this->userNames[$userId] ??= $this->users->findById($userId)?->name() ?? ('User #' . $userId);
    }

    /** @param list<array<string, mixed>> $lines @return array<int, array<string, mixed>> */
    private static function byId(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $result[(int) $line['id']] = $line;
        }

        return $result;
    }

    private static function positiveInt(mixed $value, string $message): int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return is_int($id) ? $id : throw new ValidationException($message);
    }

    /** @return list<int> */
    private static function idList(mixed $value, int $max, string $message, bool $allowEmpty = false): array
    {
        $values = is_array($value) ? array_values($value) : ($value === null || $value === '' ? [] : [$value]);
        $ids = [];
        foreach ($values as $item) {
            if ($item === '' || $item === null) {
                continue;
            }
            $ids[] = self::positiveInt($item, $message);
        }
        $ids = array_values(array_unique($ids));
        if ((!$allowEmpty && $ids === []) || count($ids) > $max) {
            throw new ValidationException($message);
        }

        return $ids;
    }

    /** Formula-safe cell; plain numbers (also negative variances) stay numbers. */
    private static function csvValue(mixed $value): string
    {
        $text = (string) $value;
        if (preg_match('/^-?\d+(\.\d+)?$/D', $text) === 1) {
            return $text;
        }

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }
}
