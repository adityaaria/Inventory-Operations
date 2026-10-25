-- D-14 / ADR-020: partial sales order fulfilment. Additive and idempotent.
--   sales_orders.status                 gains 'PartiallyFulfilled' (user decision outside the brief's SO status set);
--   sales_order_items.issued_quantity   INT UNSIGNED NOT NULL DEFAULT 0, CHECK (issued_quantity <= quantity);
--   sales_order_closures                closed remaining fulfilment (mirror of purchase_order_closures).
-- Existing Fulfilled orders were issued in one shipment of every line, so their lines get issued_quantity = quantity
-- (updated_at kept). Approved/Draft/PendingApproval/Cancelled lines keep 0. Stock, ledger and reservations are not
-- changed here; scripts/migrate-partial-fulfilment.php verifies issued_quantity against the Issue ledger and the
-- reservation invariant, and records schema version 'partial-fulfilment-1' only when both hold.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

SET @has_status := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_orders' AND column_name = 'status' AND column_type LIKE '%''PartiallyFulfilled''%');
SET @ddl := IF(@has_status = 0,
    'ALTER TABLE sales_orders MODIFY COLUMN status ENUM(''Draft'', ''PendingApproval'', ''Approved'', ''PartiallyFulfilled'', ''Fulfilled'', ''Cancelled'') NOT NULL DEFAULT ''Draft''',
    'DO 0');
PREPARE partial_fulfilment_step FROM @ddl;
EXECUTE partial_fulfilment_step;
DEALLOCATE PREPARE partial_fulfilment_step;

SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'issued_quantity');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE sales_order_items ADD COLUMN issued_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_reason',
    'DO 0');
PREPARE partial_fulfilment_step FROM @ddl;
EXECUTE partial_fulfilment_step;
DEALLOCATE PREPARE partial_fulfilment_step;

-- Backfill: a Fulfilled order shipped every line completely (issue was all-or-nothing before D-14).
UPDATE sales_order_items soi INNER JOIN sales_orders so ON so.id = soi.sales_order_id
SET soi.issued_quantity = soi.quantity, soi.updated_at = soi.updated_at
WHERE so.status = 'Fulfilled' AND soi.issued_quantity <> soi.quantity;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'sales_order_items' AND constraint_name = 'chk_sales_order_items_issued_lte_quantity');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_issued_lte_quantity CHECK (issued_quantity <= quantity)',
    'DO 0');
PREPARE partial_fulfilment_step FROM @ddl;
EXECUTE partial_fulfilment_step;
DEALLOCATE PREPARE partial_fulfilment_step;

CREATE TABLE IF NOT EXISTS sales_order_closures (
 sales_order_id INT UNSIGNED PRIMARY KEY, closed_by INT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL, closed_units INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_sales_order_closures_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id),
 CONSTRAINT fk_sales_order_closures_closed_by FOREIGN KEY (closed_by) REFERENCES users(id),
 CONSTRAINT chk_sales_order_closures_units CHECK (closed_units > 0)
) ENGINE=InnoDB;
