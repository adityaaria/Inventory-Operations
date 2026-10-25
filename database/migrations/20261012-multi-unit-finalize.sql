-- D-16 / ADR-022: multiple units of measure. Phase B (idempotent), run only after the data step has filled every unit
-- snapshot: unit columns become NOT NULL and the CHECKs guard base quantity = unit_quantity x unit_factor and whole-unit
-- receipts/shipments. A NULL left behind makes MODIFY fail, so a half-finished data step can never be finalized.

SET @needs := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_items' AND column_name IN ('unit_code', 'unit_factor', 'unit_quantity') AND is_nullable = 'YES');
SET @ddl := IF(@needs > 0,
    'ALTER TABLE purchase_order_items MODIFY unit_code VARCHAR(30) NOT NULL, MODIFY unit_factor INT UNSIGNED NOT NULL DEFAULT 1, MODIFY unit_quantity INT UNSIGNED NOT NULL',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @needs := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name IN ('unit_code', 'unit_factor', 'unit_quantity') AND is_nullable = 'YES');
SET @ddl := IF(@needs > 0,
    'ALTER TABLE sales_order_items MODIFY unit_code VARCHAR(30) NOT NULL, MODIFY unit_factor INT UNSIGNED NOT NULL DEFAULT 1, MODIFY unit_quantity INT UNSIGNED NOT NULL',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @needs := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_operation_items' AND column_name IN ('unit_code', 'unit_factor', 'unit_quantity') AND is_nullable = 'YES');
SET @ddl := IF(@needs > 0,
    'ALTER TABLE inventory_operation_items MODIFY unit_code VARCHAR(30) NOT NULL, MODIFY unit_factor INT UNSIGNED NOT NULL DEFAULT 1, MODIFY unit_quantity INT UNSIGNED NOT NULL',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'purchase_order_items' AND constraint_name = 'chk_purchase_order_items_unit_quantity');
SET @ddl := IF(@has = 0,
    'ALTER TABLE purchase_order_items ADD CONSTRAINT chk_purchase_order_items_unit_factor CHECK (unit_factor >= 1), ADD CONSTRAINT chk_purchase_order_items_unit_quantity CHECK (unit_quantity > 0 AND quantity = unit_quantity * unit_factor), ADD CONSTRAINT chk_purchase_order_items_received_whole_units CHECK (MOD(received_quantity, unit_factor) = 0)',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'sales_order_items' AND constraint_name = 'chk_sales_order_items_unit_quantity');
SET @ddl := IF(@has = 0,
    'ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_unit_factor CHECK (unit_factor >= 1), ADD CONSTRAINT chk_sales_order_items_unit_quantity CHECK (unit_quantity > 0 AND quantity = unit_quantity * unit_factor), ADD CONSTRAINT chk_sales_order_items_issued_whole_units CHECK (MOD(issued_quantity, unit_factor) = 0)',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'inventory_operation_items' AND constraint_name = 'chk_operation_items_unit');
SET @ddl := IF(@has = 0,
    'ALTER TABLE inventory_operation_items ADD CONSTRAINT chk_operation_items_unit CHECK (unit_factor >= 1 AND quantity = unit_quantity * unit_factor)',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;
