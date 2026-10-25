<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;

/**
 * Read-only invariant checks for price lists, quotations, delivery notes and invoices (D-19 / ADR-025), shared by the
 * migration runner, the seed summary and the integration tests. Every count must be 0.
 */
final class SalesDocumentChecks
{
    /** Every SO Issue ledger row is on exactly one delivery note (ledger_id is UNIQUE, so "exactly one" = "at least one"). */
    public const ISSUE_ROWS_WITHOUT_DELIVERY_NOTE = "SELECT COUNT(*) FROM stock_ledger l LEFT JOIN delivery_note_lots x ON x.ledger_id = l.id
        WHERE l.movement_type = 'Issue' AND l.reference_type = 'SO' AND x.id IS NULL";

    /** A line equals the sum of its lot rows, is unit × factor, and every lot row matches its ledger row and line. */
    public const DELIVERY_NOTE_LINE_MISMATCHES = "SELECT
          (SELECT COUNT(*) FROM delivery_note_lines dl LEFT JOIN (SELECT delivery_note_line_id, SUM(quantity) q FROM delivery_note_lots GROUP BY delivery_note_line_id) x ON x.delivery_note_line_id = dl.id
             WHERE dl.quantity <> COALESCE(x.q, 0) OR dl.quantity <> dl.unit_quantity * dl.unit_factor)
        + (SELECT COUNT(*) FROM delivery_note_lots x JOIN delivery_note_lines dl ON dl.id = x.delivery_note_line_id JOIN delivery_notes dn ON dn.id = x.delivery_note_id
             JOIN stock_ledger l ON l.id = x.ledger_id
             WHERE dl.delivery_note_id <> x.delivery_note_id OR l.movement_type <> 'Issue' OR l.reference_type <> 'SO' OR l.reference_id <> dn.sales_order_id
                OR l.product_id <> dl.product_id OR l.warehouse_id <> dn.warehouse_id OR l.quantity <> x.quantity OR NOT (l.lot_id <=> x.lot_id))
        + (SELECT COUNT(*) FROM delivery_note_lines dl JOIN delivery_notes dn ON dn.id = dl.delivery_note_id JOIN sales_order_items i ON i.id = dl.sales_order_item_id
             WHERE i.sales_order_id <> dn.sales_order_id OR i.product_id <> dl.product_id OR i.unit_factor <> dl.unit_factor OR i.unit_code <> dl.unit_code)";

    /** Σ delivery note quantities per SO line = issued quantity. */
    public const DELIVERY_NOTE_ISSUE_MISMATCHES = 'SELECT COUNT(*) FROM sales_order_items i
        LEFT JOIN (SELECT sales_order_item_id, SUM(quantity) q FROM delivery_note_lines GROUP BY sales_order_item_id) d ON d.sales_order_item_id = i.id
        WHERE i.issued_quantity <> COALESCE(d.q, 0)';

    /** Invoice lines = delivery note line unit quantity × SO line unit price; one invoice line per note line. */
    public const INVOICE_LINE_MISMATCHES = 'SELECT
          (SELECT COUNT(*) FROM invoice_lines il JOIN invoices inv ON inv.id = il.invoice_id JOIN delivery_note_lines dl ON dl.id = il.delivery_note_line_id
             JOIN sales_order_items i ON i.id = il.sales_order_item_id
             WHERE dl.delivery_note_id <> inv.delivery_note_id OR dl.sales_order_item_id <> il.sales_order_item_id OR il.unit_quantity <> dl.unit_quantity
                OR il.unit_price <> i.selling_price OR il.amount <> il.unit_quantity * il.unit_price OR il.product_id <> dl.product_id)
        + (SELECT COUNT(*) FROM invoices inv
             WHERE (SELECT COUNT(*) FROM invoice_lines il WHERE il.invoice_id = inv.id) <> (SELECT COUNT(*) FROM delivery_note_lines dl WHERE dl.delivery_note_id = inv.delivery_note_id))
        + (SELECT COUNT(*) FROM invoices inv JOIN delivery_notes dn ON dn.id = inv.delivery_note_id WHERE dn.sales_order_id <> inv.sales_order_id OR dn.customer_id <> inv.customer_id)';

    /** Subtotal = Σ lines; DPP, PPN and total recomputed exactly from the snapshot settings (ADR-025 rounding). */
    public const INVOICE_TOTAL_MISMATCHES = 'SELECT COUNT(*) FROM invoices inv
        LEFT JOIN (SELECT invoice_id, SUM(amount) s FROM invoice_lines GROUP BY invoice_id) l ON l.invoice_id = inv.id
        WHERE inv.subtotal <> COALESCE(l.s, 0)
           OR inv.dpp_amount <> (CAST(ROUND(inv.subtotal * 100) AS SIGNED) * inv.vat_dpp_numerator + 50 * inv.vat_dpp_denominator) DIV (100 * inv.vat_dpp_denominator)
           OR inv.vat_amount <> (CAST(inv.dpp_amount AS SIGNED) * inv.vat_rate_percent + 50) DIV 100
           OR inv.total_amount <> inv.subtotal + inv.vat_amount';

    /** paid_amount = Σ payments ≤ total; a void invoice has no payment; at most one live invoice per delivery note. */
    public const INVOICE_PAYMENT_MISMATCHES = 'SELECT
          (SELECT COUNT(*) FROM invoices inv LEFT JOIN (SELECT invoice_id, SUM(amount) s, COUNT(*) c FROM invoice_payments GROUP BY invoice_id) p ON p.invoice_id = inv.id
             WHERE inv.paid_amount <> COALESCE(p.s, 0) OR COALESCE(p.s, 0) > inv.total_amount OR (inv.voided_at IS NOT NULL AND COALESCE(p.c, 0) > 0))
        + (SELECT COUNT(*) FROM (SELECT delivery_note_id FROM invoices WHERE voided_at IS NULL GROUP BY delivery_note_id HAVING COUNT(*) > 1) d)';

    /** One SO per quotation, converted only from Accepted, converted lines carry the quoted price, list price and source. */
    public const QUOTATION_CONVERSION_MISMATCHES = "SELECT
          (SELECT COUNT(*) FROM (SELECT quotation_id FROM sales_orders WHERE quotation_id IS NOT NULL GROUP BY quotation_id HAVING COUNT(*) > 1) d)
        + (SELECT COUNT(*) FROM sales_orders so JOIN quotations q ON q.id = so.quotation_id WHERE q.status <> 'Accepted' OR q.customer_id <> so.customer_id)
        + (SELECT COUNT(*) FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id JOIN quotation_items qi ON qi.id = i.quotation_item_id
             WHERE so.quotation_id IS NULL OR qi.quotation_id <> so.quotation_id OR qi.product_id <> i.product_id OR qi.unit_price <> i.selling_price
                OR qi.list_price <> i.list_price OR qi.unit_quantity <> i.unit_quantity OR qi.unit_code <> i.unit_code OR qi.price_source <> i.price_source
                OR NOT (qi.price_list_id <=> i.price_list_id))
        + (SELECT COUNT(*) FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id WHERE so.quotation_id IS NOT NULL AND i.quotation_item_id IS NULL)";

    /** A price-list line names a list with an item for its product and unit; source and list id agree (also a CHECK). */
    public const PRICE_SOURCE_MISMATCHES = "SELECT
          (SELECT COUNT(*) FROM sales_order_items i WHERE (i.price_source = 'MASTER') <> (i.price_list_id IS NULL))
        + (SELECT COUNT(*) FROM quotation_items i WHERE (i.price_source = 'MASTER') <> (i.price_list_id IS NULL))
        + (SELECT COUNT(*) FROM sales_order_items i WHERE i.price_source = 'PRICE_LIST' AND NOT EXISTS
             (SELECT 1 FROM price_list_items pli WHERE pli.price_list_id = i.price_list_id AND pli.product_id = i.product_id AND pli.unit_code = i.unit_code))
        + (SELECT COUNT(*) FROM quotation_items i WHERE i.price_source = 'PRICE_LIST' AND NOT EXISTS
             (SELECT 1 FROM price_list_items pli WHERE pli.price_list_id = i.price_list_id AND pli.product_id = i.product_id AND pli.unit_code = i.unit_code))";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function report(): array
    {
        return [
            'issue_rows_without_delivery_note' => $this->count(self::ISSUE_ROWS_WITHOUT_DELIVERY_NOTE),
            'delivery_note_line_mismatches' => $this->count(self::DELIVERY_NOTE_LINE_MISMATCHES),
            'delivery_note_issue_mismatches' => $this->count(self::DELIVERY_NOTE_ISSUE_MISMATCHES),
            'invoice_line_mismatches' => $this->count(self::INVOICE_LINE_MISMATCHES),
            'invoice_total_mismatches' => $this->count(self::INVOICE_TOTAL_MISMATCHES),
            'invoice_payment_mismatches' => $this->count(self::INVOICE_PAYMENT_MISMATCHES),
            'quotation_conversion_mismatches' => $this->count(self::QUOTATION_CONVERSION_MISMATCHES),
            'price_source_mismatches' => $this->count(self::PRICE_SOURCE_MISMATCHES),
        ];
    }

    /** @return array<string, mixed> counts for evidence (not invariants) */
    public function statistics(): array
    {
        $scalar = fn (string $sql): int => $this->count($sql);
        $today = 'DATE(UTC_TIMESTAMP() + INTERVAL ' . \App\Support\BusinessDate::offsetSeconds() . ' SECOND)';

        return [
            'price_lists' => $scalar('SELECT COUNT(*) FROM price_lists'),
            'price_list_items' => $scalar('SELECT COUNT(*) FROM price_list_items'),
            'customers_with_price_list' => $scalar('SELECT COUNT(*) FROM customers WHERE price_list_id IS NOT NULL'),
            'so_lines_from_price_list' => $scalar("SELECT COUNT(*) FROM sales_order_items WHERE price_source = 'PRICE_LIST'"),
            'quotations' => $scalar('SELECT COUNT(*) FROM quotations'),
            'quotations_by_status' => $this->pairs("SELECT CASE WHEN status IN ('Draft', 'Sent') AND valid_until < {$today} THEN 'Expired' ELSE status END s, COUNT(*) FROM quotations GROUP BY s ORDER BY s"),
            'quotations_converted' => $scalar('SELECT COUNT(*) FROM sales_orders WHERE quotation_id IS NOT NULL'),
            'delivery_notes' => $scalar('SELECT COUNT(*) FROM delivery_notes'),
            'delivery_notes_by_origin' => $this->pairs('SELECT origin, COUNT(*) FROM delivery_notes GROUP BY origin ORDER BY origin'),
            'delivery_notes_with_vehicle' => $scalar('SELECT COUNT(*) FROM delivery_notes WHERE vehicle IS NOT NULL'),
            'invoices' => $scalar('SELECT COUNT(*) FROM invoices'),
            'invoices_by_status' => $this->pairs("SELECT CASE WHEN voided_at IS NOT NULL THEN 'Void' WHEN paid_amount >= total_amount THEN 'Paid' WHEN paid_amount > 0 THEN 'PartiallyPaid' ELSE 'Unpaid' END s, COUNT(*) FROM invoices GROUP BY s ORDER BY s"),
            'invoices_overdue' => $scalar("SELECT COUNT(*) FROM invoices WHERE voided_at IS NULL AND paid_amount < total_amount AND due_date < {$today}"),
            'delivery_notes_not_invoiced' => $scalar('SELECT COUNT(*) FROM delivery_notes dn WHERE NOT EXISTS (SELECT 1 FROM invoices i WHERE i.active_delivery_note_id = dn.id)'),
            'payments' => $scalar('SELECT COUNT(*) FROM invoice_payments'),
            'receivables_outstanding' => $this->text('SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM invoices WHERE voided_at IS NULL'),
            'invoiced_total' => $this->text('SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE voided_at IS NULL'),
            'invoiced_vat' => $this->text('SELECT COALESCE(SUM(vat_amount), 0) FROM invoices WHERE voided_at IS NULL'),
        ];
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    private function text(string $sql): string
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? '' : (string) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    private function pairs(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? [] : array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
