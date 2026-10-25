<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\InventoryValuationRepositoryInterface;
use App\Security\AuthContext;
use App\Support\BusinessDate;
use App\Support\CostAmount;
use App\Support\Pagination;
use App\Support\TaxSettings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Inventory valuation FIFO per batch (D-20, ADR-026; user decision, not trainer).
 *
 * Every lot keeps its own unit cost (ADR-023) and every ledger row with a lot carries cost_amount = signed quantity ×
 * lot unit cost. Value of a lot = quantity × unit cost; value at an earlier moment = current value − Σ cost_amount of
 * the rows written since (valid only after the valuation start: earlier rows have no cost and are never given one).
 * In transit = units dispatched and not yet received or written off, at their lot cost (ADR-021). COGS of a shipment =
 * −Σ cost_amount of its Issue rows (= its delivery-note lots × lot cost, ADR-025); gross margin = invoice net revenue
 * (excl. PPN) − COGS.
 *
 * Authorization: every cost and margin read model is Admin only (403 for Sales and Warehouse, checked here before any
 * input is parsed). Warehouse keeps seeing lot unit costs where ADR-023/024 show them.
 *
 * Periods are business dates (APP_TIMEZONE); a day ends at midnight in that zone, converted to UTC for the ledger.
 */
final class InventoryValuationService
{
    public const GROUPS = ['warehouse' => 'Warehouse', 'category' => 'Category', 'product' => 'Product', 'lot' => 'Lot'];
    public const MARGIN_GROUPS = ['customer' => 'Customer', 'product' => 'Product', 'line' => 'Invoice line'];
    public const LOSS_KINDS = ['ExpiredWriteOff' => 'Expired write-off', 'TransitDamage' => 'Transit damage', 'TransitShortage' => 'Transit shortage', 'CountShortfall' => 'Count shortfall'];
    /** Reconciliation columns (signed cents): opening + Σ movements = closing. */
    public const COLUMNS = [
        'opening' => 'Opening value',
        'receipts' => 'Receipts',
        'transfers_in' => 'Transfers in',
        'issues' => 'Issues (COGS)',
        'transfers_out' => 'Transfers out',
        'adjustments' => 'Adjustments and returns',
        'counts' => 'Count variances',
        'write_offs' => 'Expired write-offs',
        'transit_losses' => 'Transit losses',
        'closing' => 'Closing value',
    ];
    private const MOVEMENT_COLUMNS = ['receipts', 'transfers_in', 'issues', 'transfers_out', 'adjustments', 'counts', 'write_offs', 'transit_losses'];
    private const CLASS_COLUMN = [
        'receipt' => 'receipts', 'issue' => 'issues', 'transfer_in' => 'transfers_in', 'transfer_in_unvalued' => 'transfers_in', 'transfer_out' => 'transfers_out',
        'adjustment' => 'adjustments', 'supplier_return' => 'adjustments', 'customer_return' => 'adjustments', 'other' => 'adjustments', 'count' => 'counts', 'write_off' => 'write_offs',
    ];
    public const PAGE_SIZE = 50;

    public function __construct(private readonly InventoryValuationRepositoryInterface $repository)
    {
    }

    /**
     * Dashboard card: on hand and in transit at lot cost, now.
     *
     * @return array{on_hand_cents: int, in_transit_cents: int, total_cents: int, unvalued_in_transit_units: int, started_at: string|null}
     */
    public function summary(AuthContext $actor): array
    {
        $this->assertAdmin($actor);
        $onHand = 0;
        foreach ($this->repository->lots(null, []) as $lot) {
            $onHand += CostAmount::valueCents($lot['quantity'], $lot['unit_cost']);
        }
        $transit = $this->transitCents($this->repository->inTransit(null, []));

        return ['on_hand_cents' => $onHand, 'in_transit_cents' => $transit, 'total_cents' => $onHand + $transit,
            'unvalued_in_transit_units' => $this->repository->unvaluedInTransitUnits(null), 'started_at' => $this->repository->valuationStart()['started_at']];
    }

    /**
     * Inventory valuation, current or as of the end of a business date, grouped by warehouse, category, product or lot.
     *
     * @param array<string, mixed> $query warehouse_id, category_id, q, by, as_of, page
     * @return array<string, mixed>
     */
    public function valuation(AuthContext $actor, array $query, bool $paginate = true): array
    {
        $this->assertAdmin($actor);
        $filters = self::filters($query);
        $by = is_string($query['by'] ?? null) && isset(self::GROUPS[$query['by']]) ? (string) $query['by'] : 'warehouse';
        $start = $this->repository->valuationStart();
        $today = $this->repository->today();
        $asOf = trim(is_string($query['as_of'] ?? null) ? (string) $query['as_of'] : '');
        $atUtc = null;
        if ($asOf !== '') {
            if (!BusinessDate::isValidDate($asOf)) {
                throw new ValidationException('Enter the as-of date as YYYY-MM-DD.');
            }
            if ($asOf > $today) {
                throw new ValidationException('The as-of date cannot be in the future.');
            }
            $atUtc = self::endUtc($asOf);
            if ($start['started_at'] !== null && $atUtc < $start['started_at']) {
                throw new ValidationException('The valuation starts at ' . self::label($start['started_at']) . ': movements before it have no cost, so no value can be computed for ' . $asOf . '. Choose a later date.');
            }
            if ($asOf === $today) {
                $atUtc = null; // the end of today is "now": every committed row is included.
            }
        }
        $groups = [];
        $totals = ['quantity' => 0, 'value_cents' => 0, 'lots' => 0];
        foreach ($this->repository->lots($atUtc, $filters) as $lot) {
            $quantity = $lot['quantity'] - $lot['units_after'];
            $value = CostAmount::valueCents($lot['quantity'], $lot['unit_cost']) - $lot['cents_after'];
            if ($quantity === 0 && $value === 0) {
                continue;
            }
            [$key, $labels] = match ($by) {
                'category' => [(string) $lot['category_id'], ['category_name' => $lot['category_name']]],
                'product' => [$lot['product_id'] . ':' . $lot['warehouse_id'], ['sku' => $lot['sku'], 'product_name' => $lot['product_name'], 'category_name' => $lot['category_name'], 'warehouse_name' => $lot['warehouse_name'], 'base_unit' => $lot['base_unit']]],
                'lot' => [(string) $lot['lot_id'], ['lot_id' => $lot['lot_id'], 'lot_code' => $lot['lot_code'], 'expiry_date' => $lot['expiry_date'], 'unit_cost' => $lot['unit_cost'], 'sku' => $lot['sku'], 'product_name' => $lot['product_name'], 'category_name' => $lot['category_name'], 'warehouse_name' => $lot['warehouse_name'], 'base_unit' => $lot['base_unit']]],
                default => [(string) $lot['warehouse_id'], ['warehouse_name' => $lot['warehouse_name']]],
            };
            $groups[$key] ??= $labels + ['quantity' => 0, 'value_cents' => 0, 'lots' => 0];
            $groups[$key]['quantity'] += $quantity;
            $groups[$key]['value_cents'] += $value;
            ++$groups[$key]['lots'];
            $totals['quantity'] += $quantity;
            $totals['value_cents'] += $value;
            ++$totals['lots'];
        }
        $rows = array_values($groups);
        if (in_array($by, ['warehouse', 'category'], true)) {
            usort($rows, static fn (array $a, array $b): int => [$b['value_cents'], $a[$by . '_name']] <=> [$a['value_cents'], $b[$by . '_name']]);
        }
        $transit = array_map(static fn (array $row): array => $row + ['value_cents' => CostAmount::valueCents($row['units'], $row['unit_cost'])], $this->repository->inTransit($atUtc, $filters));
        $transitCents = array_sum(array_column($transit, 'value_cents'));
        $total = count($rows);
        $page = 1;
        if ($paginate) {
            $page = min(max(1, self::page($query)), max(1, (int) ceil($total / self::PAGE_SIZE)));
            $rows = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        }

        return [
            'filters' => $filters, 'by' => $by, 'as_of' => $asOf, 'today' => $today, 'start' => $start,
            'rows' => $rows, 'row_count' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'totals' => $totals, 'transit' => $transit, 'transit_cents' => $transitCents,
            'unvalued_in_transit_units' => $this->repository->unvaluedInTransitUnits($atUtc),
            'total_cents' => $totals['value_cents'] + $transitCents,
        ];
    }

    /** @param array<string, mixed> $query */
    public function valuationCsv(AuthContext $actor, array $query): string
    {
        $report = $this->valuation($actor, $query, false);
        $columns = match ($report['by']) {
            'category' => ['category_name'],
            'product' => ['sku', 'product_name', 'category_name', 'warehouse_name', 'base_unit'],
            'lot' => ['lot_code', 'expiry_date', 'sku', 'product_name', 'category_name', 'warehouse_name', 'base_unit', 'unit_cost'],
            default => ['warehouse_name'],
        };
        $asOf = $report['as_of'] === '' ? $report['today'] . ' (current)' : $report['as_of'];
        $lines = [['section', ...$columns, 'quantity', 'lots', 'value', 'as_of']];
        foreach ($report['rows'] as $row) {
            $lines[] = ['on_hand', ...array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), $columns), (string) $row['quantity'], (string) $row['lots'], TaxSettings::decimal($row['value_cents']), $asOf];
        }
        foreach ($report['transit'] as $row) {
            $lines[] = ['in_transit', ...array_map(static fn (string $column): string => match ($column) {
                'warehouse_name' => $row['source_name'] . ' -> ' . $row['destination_name'], 'base_unit' => '', 'expiry_date' => (string) ($row['expiry_date'] ?? ''), default => (string) ($row[$column] ?? ''),
            }, $columns), (string) $row['units'], '1', TaxSettings::decimal($row['value_cents']), $asOf];
        }
        $blank = array_fill(0, count($columns), '');
        $lines[] = ['TOTAL on hand', ...$blank, (string) $report['totals']['quantity'], (string) $report['totals']['lots'], TaxSettings::decimal($report['totals']['value_cents']), $asOf];
        $lines[] = ['TOTAL in transit', ...$blank, (string) array_sum(array_column($report['transit'], 'units')), (string) count($report['transit']), TaxSettings::decimal($report['transit_cents']), $asOf];
        $lines[] = ['TOTAL inventory', ...$blank, '', '', TaxSettings::decimal($report['total_cents']), $asOf];

        return self::csv($lines);
    }

    /**
     * Movement valuation / reconciliation per warehouse for a period of business dates: opening + receipts + transfers in
     * + issues + transfers out + adjustments + counts + write-offs (signed) = closing, plus the in-transit line and the
     * total. Opening and closing are computed independently from the lot values and the ledger, so a difference ≠ 0
     * exposes a broken invariant.
     *
     * @param array<string, mixed> $query from, to
     * @return array<string, mixed>
     */
    public function reconciliation(AuthContext $actor, array $query): array
    {
        $this->assertAdmin($actor);
        $today = $this->repository->today();
        [$from, $to] = self::period($query, substr($today, 0, 8) . '01', $today);
        $start = $this->repository->valuationStart();
        $fromUtc = self::startUtc($from);
        $toUtc = self::endUtc($to);
        if ($start['started_at'] !== null && $toUtc <= $start['started_at']) {
            throw new ValidationException('The valuation starts at ' . self::label($start['started_at']) . '; choose a period that ends after it.');
        }
        $rows = [];
        $name = [];
        foreach ([['opening', $fromUtc], ['closing', $toUtc]] as [$column, $at]) {
            foreach ($this->repository->lots($at, []) as $lot) {
                $name[$lot['warehouse_id']] = $lot['warehouse_name'];
                $rows[$lot['warehouse_id']] ??= array_fill_keys(array_keys(self::COLUMNS), 0);
                $rows[$lot['warehouse_id']][$column] += CostAmount::valueCents($lot['quantity'], $lot['unit_cost']) - $lot['cents_after'];
            }
        }
        $movements = $this->repository->movementTotals($fromUtc, $toUtc);
        foreach ($movements as $movement) {
            $rows[$movement['warehouse_id']] ??= array_fill_keys(array_keys(self::COLUMNS), 0);
            $rows[$movement['warehouse_id']][self::CLASS_COLUMN[$movement['class']] ?? 'adjustments'] += $movement['cents'];
        }
        ksort($rows);
        $result = [];
        foreach ($rows as $warehouseId => $row) {
            $result[] = ['label' => $name[$warehouseId] ?? ('Warehouse #' . $warehouseId), 'kind' => 'warehouse'] + self::withDifference($row);
        }
        // In transit (ADR-021): value leaves the source warehouses into transit and enters the destinations from it.
        $warehouseOut = array_sum(array_column($result, 'transfers_out'));
        $valuedIn = 0;
        foreach ($movements as $movement) {
            $valuedIn += $movement['class'] === 'transfer_in' ? $movement['cents'] : 0;
        }
        $losses = 0;
        foreach ($this->repository->losses($fromUtc, $toUtc) as $loss) {
            if (in_array($loss['kind'], ['TransitDamage', 'TransitShortage'], true)) {
                $losses += CostAmount::valueCents($loss['units'], $loss['unit_cost']);
            }
        }
        $transit = array_fill_keys(array_keys(self::COLUMNS), 0);
        $transit['opening'] = $this->transitCents($this->repository->inTransit($fromUtc, []));
        $transit['closing'] = $this->transitCents($this->repository->inTransit($toUtc, []));
        $transit['transfers_in'] = -$warehouseOut;
        $transit['transfers_out'] = -$valuedIn;
        $transit['transit_losses'] = -$losses;
        $result[] = ['label' => 'In transit', 'kind' => 'transit'] + self::withDifference($transit);
        $total = array_fill_keys([...array_keys(self::COLUMNS), 'computed', 'difference'], 0);
        foreach ($result as $row) {
            foreach (array_keys($total) as $column) {
                $total[$column] += $row[$column];
            }
        }
        $result[] = ['label' => 'Total', 'kind' => 'total'] + $total;

        return [
            'from' => $from, 'to' => $to, 'from_utc' => $fromUtc, 'to_utc' => $toUtc, 'start' => $start,
            'opening_at_valuation_start' => $start['started_at'] !== null && $fromUtc < $start['started_at'],
            'unvalued_movements' => $this->repository->unvaluedMovements($fromUtc, $toUtc),
            'rows' => $result, 'reconciled' => array_sum(array_map(static fn (array $row): int => abs($row['difference']), $result)) === 0,
        ];
    }

    /** @param array<string, mixed> $query */
    public function reconciliationCsv(AuthContext $actor, array $query): string
    {
        $report = $this->reconciliation($actor, $query);
        $lines = [['line', ...array_keys(self::COLUMNS), 'computed_closing', 'difference', 'from', 'to']];
        foreach ($report['rows'] as $row) {
            $lines[] = [$row['label'], ...array_map(static fn (string $column): string => TaxSettings::decimal($row[$column]), array_keys(self::COLUMNS)),
                TaxSettings::decimal($row['computed']), TaxSettings::decimal($row['difference']), $report['from'], $report['to']];
        }

        return self::csv($lines);
    }

    /**
     * Gross margin of invoiced shipments: net revenue (invoice lines, excl. PPN) − COGS of the shipped lots, per invoice
     * line, customer or product, for invoices dated in the period (void invoices excluded). Shipments without a live
     * invoice are listed separately ("shipped not invoiced"). Lines shipped before the valuation start have no COGS; their
     * revenue is reported apart and never enters the margin.
     *
     * @param array<string, mixed> $query from, to, customer_id, q, group
     * @return array<string, mixed>
     */
    public function margin(AuthContext $actor, array $query): array
    {
        $this->assertAdmin($actor);
        $today = $this->repository->today();
        [$from, $to] = self::period($query, substr($today, 0, 8) . '01', $today);
        $group = is_string($query['group'] ?? null) && isset(self::MARGIN_GROUPS[$query['group']]) ? (string) $query['group'] : 'customer';
        $filters = ['customer_id' => self::positive($query['customer_id'] ?? null), 'q' => self::search($query['q'] ?? null)];
        $lines = array_map(static fn (array $line): array => self::marginOf($line), $this->repository->marginLines($from, $to, $filters));
        $totals = self::marginTotals($lines);
        $groups = [];
        if ($group !== 'line') {
            foreach ($lines as $line) {
                $key = $group === 'product' ? (string) $line['product_id'] : (string) $line['customer_id'];
                $groups[$key] ??= ($group === 'product' ? ['label' => $line['sku'] . ' — ' . $line['product_name'], 'category_name' => $line['category_name']] : ['label' => (string) $line['customer_name']]) + ['lines' => []];
                $groups[$key]['lines'][] = $line;
            }
            $groups = array_map(static fn (array $entry): array => array_diff_key($entry, ['lines' => true]) + self::marginTotals($entry['lines']), array_values($groups));
            usort($groups, static fn (array $a, array $b): int => [$b['revenue_cents'], $a['label']] <=> [$a['revenue_cents'], $b['label']]);
        }
        $notInvoiced = array_map(static fn (array $line): array => self::marginOf($line), $this->repository->shippedNotInvoiced(self::startUtc($from), self::endUtc($to), $filters));

        return [
            'from' => $from, 'to' => $to, 'group' => $group, 'filters' => $filters, 'start' => $this->repository->valuationStart(),
            'lines' => $lines, 'groups' => $groups, 'totals' => $totals,
            'not_invoiced' => $notInvoiced, 'not_invoiced_totals' => self::marginTotals($notInvoiced),
        ];
    }

    /** @param array<string, mixed> $query */
    public function marginCsv(AuthContext $actor, array $query): string
    {
        $report = $this->margin($actor, $query);
        $lines = [['section', 'date', 'invoice', 'delivery_note', 'order', 'customer', 'sku', 'product', 'category', 'unit', 'unit_quantity', 'base_quantity', 'net_revenue', 'cogs', 'gross_margin', 'margin_percent', 'valued']];
        foreach ([['invoiced', $report['lines']], ['shipped_not_invoiced', $report['not_invoiced']]] as [$section, $rows]) {
            foreach ($rows as $row) {
                $lines[] = [$section, (string) ($row['invoice_date'] ?? substr((string) ($row['shipped_at'] ?? ''), 0, 10)), (string) ($row['invoice_number'] ?? ''), (string) $row['delivery_number'],
                    (string) $row['order_number'], (string) $row['customer_name'], (string) $row['sku'], (string) $row['product_name'], (string) $row['category_name'], (string) $row['unit_code'],
                    (string) $row['unit_quantity'], (string) $row['base_quantity'], TaxSettings::decimal($row['revenue_cents']),
                    $row['valued'] ? TaxSettings::decimal($row['cogs_cents']) : '', $row['valued'] ? TaxSettings::decimal($row['margin_cents']) : '',
                    $row['margin_percent'] === null ? '' : number_format($row['margin_percent'], 1, '.', ''), $row['valued'] ? 'yes' : 'no (shipped before valuation start)'];
            }
        }
        $t = $report['totals'];
        $lines[] = ['TOTAL invoiced', $report['from'] . '..' . $report['to'], '', '', '', '', '', '', '', '', '', '', TaxSettings::decimal($t['revenue_cents']), TaxSettings::decimal($t['cogs_cents']),
            TaxSettings::decimal($t['margin_cents']), $t['margin_percent'] === null ? '' : number_format($t['margin_percent'], 1, '.', ''), 'unvalued revenue ' . TaxSettings::decimal($t['unvalued_revenue_cents'])];

        return self::csv($lines);
    }

    /**
     * Losses at cost in a period: expired write-offs, transit damage and shortage, negative count variances.
     *
     * @param array<string, mixed> $query from, to
     * @return array<string, mixed>
     */
    public function losses(AuthContext $actor, array $query): array
    {
        $this->assertAdmin($actor);
        $today = $this->repository->today();
        [$from, $to] = self::period($query, substr($today, 0, 8) . '01', $today);
        $rows = [];
        $totals = array_fill_keys(array_keys(self::LOSS_KINDS), ['units' => 0, 'value_cents' => 0, 'events' => 0]);
        foreach ($this->repository->losses(self::startUtc($from), self::endUtc($to)) as $row) {
            $value = $row['ledger_cents'] ?? CostAmount::valueCents($row['units'], $row['unit_cost']);
            $rows[] = $row + ['value_cents' => $value, 'kind_label' => self::LOSS_KINDS[$row['kind']] ?? $row['kind']];
            $totals[$row['kind']]['units'] += $row['units'];
            $totals[$row['kind']]['value_cents'] += $value;
            ++$totals[$row['kind']]['events'];
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals, 'total_cents' => array_sum(array_column($totals, 'value_cents')), 'start' => $this->repository->valuationStart()];
    }

    /** @param array<string, mixed> $query */
    public function lossesCsv(AuthContext $actor, array $query): string
    {
        $report = $this->losses($actor, $query);
        $lines = [['date', 'kind', 'document', 'warehouse', 'sku', 'product', 'lot', 'units', 'unit_cost', 'value']];
        foreach ($report['rows'] as $row) {
            $lines[] = [self::label($row['occurred_at']), $row['kind_label'], $row['document_type'] . ' #' . $row['document_id'], $row['warehouse_name'], $row['sku'], $row['product_name'], $row['lot_code'],
                (string) $row['units'], $row['unit_cost'], TaxSettings::decimal($row['value_cents'])];
        }
        foreach ($report['totals'] as $kind => $total) {
            $lines[] = ['TOTAL ' . self::LOSS_KINDS[$kind], '', '', '', '', '', '', (string) $total['units'], '', TaxSettings::decimal($total['value_cents'])];
        }
        $lines[] = ['TOTAL', '', '', '', '', '', '', '', '', TaxSettings::decimal($report['total_cents'])];

        return self::csv($lines);
    }

    /**
     * COGS and margin of a sales order's shipments (Admin only): per delivery note, its lines' COGS from the lots shipped
     * and, when invoiced, the net revenue and margin.
     *
     * @return array<string, mixed>
     */
    public function orderCosting(AuthContext $actor, int $orderId): array
    {
        $this->assertAdmin($actor);

        return $this->costing($this->repository->documentLines($this->repository->deliveryNoteIdsForOrder($orderId)));
    }

    /** @return array<string, mixed> */
    public function deliveryNoteCosting(AuthContext $actor, int $deliveryNoteId): array
    {
        $this->assertAdmin($actor);

        return $this->costing($this->repository->documentLines([$deliveryNoteId]));
    }

    /**
     * Invoice detail: COGS per invoice line (matched by delivery-note line) and margin against the invoice's own net
     * amounts (also for a void invoice, for reference).
     *
     * @param list<array<string, mixed>> $invoiceLines with delivery_note_line_id and amount
     * @return array<string, mixed>
     */
    public function invoiceCosting(AuthContext $actor, int $deliveryNoteId, array $invoiceLines): array
    {
        $this->assertAdmin($actor);
        $cogs = [];
        foreach ($this->repository->documentLines([$deliveryNoteId]) as $line) {
            $cogs[$line['delivery_note_line_id']] = $line;
        }
        $lines = [];
        foreach ($invoiceLines as $invoiceLine) {
            $source = $cogs[(int) $invoiceLine['delivery_note_line_id']] ?? null;
            $lines[(int) $invoiceLine['delivery_note_line_id']] = self::marginOf(['revenue_cents' => TaxSettings::cents((string) $invoiceLine['amount']),
                'cogs_cents' => $source['cogs_cents'] ?? 0, 'unvalued_units' => $source === null ? 1 : $source['unvalued_units']]);
        }

        return ['lines' => $lines, 'totals' => self::marginTotals(array_values($lines))];
    }

    /**
     * @param list<array<string, mixed>> $documentLines
     * @return array<string, mixed>
     */
    private function costing(array $documentLines): array
    {
        $notes = [];
        foreach ($documentLines as $line) {
            $id = $line['delivery_note_id'];
            $notes[$id] ??= ['delivery_note_id' => $id, 'delivery_number' => $line['delivery_number'], 'shipment_number' => $line['shipment_number'], 'shipped_at' => $line['shipped_at'],
                'invoice_id' => $line['invoice_id'], 'invoice_number' => $line['invoice_number'], 'lines' => []];
            // Revenue = the live invoice line's net amount; the SO line value is kept as so_value_cents for reference.
            $revenue = $line['invoice_cents'];
            $notes[$id]['lines'][] = self::marginOf(['revenue_cents' => $revenue ?? 0, 'cogs_cents' => $line['cogs_cents'], 'unvalued_units' => $line['unvalued_units']])
                + ['invoiced' => $revenue !== null, 'so_value_cents' => $line['revenue_cents']] + $line;
        }
        $summary = ['cogs_cents' => 0, 'unvalued_units' => 0, 'invoiced_revenue_cents' => 0, 'invoiced_cogs_cents' => 0, 'not_invoiced_cogs_cents' => 0];
        foreach ($notes as &$note) {
            $note['cogs_cents'] = array_sum(array_column($note['lines'], 'cogs_cents'));
            $note['unvalued_units'] = array_sum(array_column($note['lines'], 'unvalued_units'));
            $invoiced = array_values(array_filter($note['lines'], static fn (array $line): bool => $line['invoiced']));
            $note['margin'] = $note['invoice_id'] === null ? null : self::marginTotals($invoiced);
            $summary['cogs_cents'] += $note['cogs_cents'];
            $summary['unvalued_units'] += $note['unvalued_units'];
            if ($note['invoice_id'] === null) {
                $summary['not_invoiced_cogs_cents'] += $note['cogs_cents'];
            } else {
                $summary['invoiced_revenue_cents'] += $note['margin']['revenue_cents'];
                $summary['invoiced_cogs_cents'] += $note['margin']['cogs_cents'];
            }
        }
        unset($note);
        $summary['invoiced_margin_cents'] = $summary['invoiced_revenue_cents'] - $summary['invoiced_cogs_cents'];
        $summary['invoiced_margin_percent'] = CostAmount::marginPercent($summary['invoiced_revenue_cents'], $summary['invoiced_cogs_cents']);

        return ['notes' => array_values($notes), 'summary' => $summary];
    }

    /**
     * @param array<string, mixed> $line revenue_cents, cogs_cents, unvalued_units
     * @return array<string, mixed>
     */
    public static function marginOf(array $line): array
    {
        $valued = (int) ($line['unvalued_units'] ?? 0) === 0;
        $revenue = (int) $line['revenue_cents'];
        $cogs = (int) $line['cogs_cents'];

        return ['valued' => $valued, 'margin_cents' => $valued ? $revenue - $cogs : null, 'margin_percent' => $valued ? CostAmount::marginPercent($revenue, $cogs) : null] + $line;
    }

    /**
     * Margin totals over valued lines; revenue of unvalued lines (shipped before the valuation start) is kept apart.
     *
     * @param list<array<string, mixed>> $lines output of marginOf()
     * @return array{revenue_cents: int, cogs_cents: int, margin_cents: int, margin_percent: float|null, unvalued_revenue_cents: int, lines: int}
     */
    public static function marginTotals(array $lines): array
    {
        $revenue = $cogs = $unvalued = 0;
        foreach ($lines as $line) {
            if ($line['valued']) {
                $revenue += (int) $line['revenue_cents'];
                $cogs += (int) $line['cogs_cents'];
            } else {
                $unvalued += (int) $line['revenue_cents'];
            }
        }

        return ['revenue_cents' => $revenue, 'cogs_cents' => $cogs, 'margin_cents' => $revenue - $cogs, 'margin_percent' => CostAmount::marginPercent($revenue, $cogs), 'unvalued_revenue_cents' => $unvalued, 'lines' => count($lines)];
    }

    /** @param list<array{units: int, unit_cost: string}> $rows */
    private function transitCents(array $rows): int
    {
        return array_sum(array_map(static fn (array $row): int => CostAmount::valueCents($row['units'], $row['unit_cost']), $rows));
    }

    /**
     * @param array<string, int> $row
     * @return array<string, int>
     */
    private static function withDifference(array $row): array
    {
        $computed = $row['opening'];
        foreach (self::MOVEMENT_COLUMNS as $column) {
            $computed += $row[$column];
        }

        return $row + ['computed' => $computed, 'difference' => $row['closing'] - $computed];
    }

    /** UTC start of a business date (midnight in APP_TIMEZONE). */
    public static function startUtc(string $date): string
    {
        return (new DateTimeImmutable($date . ' 00:00:00', BusinessDate::timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC end (exclusive) of a business date = start of the next day. */
    public static function endUtc(string $date): string
    {
        return self::startUtc((new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d'));
    }

    /** A stored UTC timestamp shown in the business time zone. */
    public static function label(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i');
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private static function period(array $query, string $defaultFrom, string $defaultTo): array
    {
        $from = is_string($query['from'] ?? null) && trim((string) $query['from']) !== '' ? trim((string) $query['from']) : $defaultFrom;
        $to = is_string($query['to'] ?? null) && trim((string) $query['to']) !== '' ? trim((string) $query['to']) : $defaultTo;
        if (!BusinessDate::isValidDate($from) || !BusinessDate::isValidDate($to) || $from > $to) {
            throw new ValidationException('Enter a valid date range (from ≤ to, YYYY-MM-DD).');
        }

        return [$from, $to];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{warehouse_id: int, category_id: int, q: string}
     */
    private static function filters(array $query): array
    {
        return ['warehouse_id' => self::positive($query['warehouse_id'] ?? null), 'category_id' => self::positive($query['category_id'] ?? null), 'q' => self::search($query['q'] ?? null)];
    }

    private static function positive(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $id = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new ValidationException('Invalid filter.');
        }

        return (int) $id;
    }

    private static function search(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || mb_strlen($value) > 120) {
            throw new ValidationException('Search text must be at most 120 characters.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $query */
    private static function page(array $query): int
    {
        $value = $query['page'] ?? 1;
        $page = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return is_int($page) ? $page : 1;
    }

    private function assertAdmin(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to build CSV.');
        }
        foreach ($rows as $row) {
            // Neutralize spreadsheet formulas in text cells; plain numbers (also negative ones) stay numbers.
            fputcsv($handle, array_map(static fn (string $cell): string => preg_match('/^-?\d+(?:\.\d+)?$/D', $cell) !== 1 && preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell, $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
