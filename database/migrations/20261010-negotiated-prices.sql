-- D-13 / ADR-019: negotiated PO/SO line prices with a mandatory reason. Additive and idempotent.
-- For purchase_order_items and sales_order_items:
--   list_price   DECIMAL(14,2) NOT NULL  master price snapshot at draft creation (same type as the line price column);
--   price_reason VARCHAR(255) NULL       why the line price differs from list_price (NULL when it does not).
-- Existing lines were priced from the master (D-09, ADR-015), so list_price is backfilled from the line price and every
-- existing reason stays NULL. The backfill keeps updated_at unchanged. purchase_price / selling_price stay the effective
-- line price, so reports and dashboards are unaffected. Stock, ledger and order statuses are never touched.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

-- purchase_order_items ------------------------------------------------------------------------------------------------
SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_items' AND column_name = 'list_price');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE purchase_order_items ADD COLUMN list_price DECIMAL(14,2) NULL AFTER purchase_price',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

UPDATE purchase_order_items SET list_price = purchase_price, updated_at = updated_at WHERE list_price IS NULL;

SET @is_nullable := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_items' AND column_name = 'list_price' AND is_nullable = 'YES');
SET @ddl := IF(@is_nullable = 1,
    'ALTER TABLE purchase_order_items MODIFY COLUMN list_price DECIMAL(14,2) NOT NULL',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_items' AND column_name = 'price_reason');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE purchase_order_items ADD COLUMN price_reason VARCHAR(255) NULL DEFAULT NULL AFTER list_price',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'purchase_order_items' AND constraint_name = 'chk_purchase_order_items_list_price_non_negative');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE purchase_order_items ADD CONSTRAINT chk_purchase_order_items_list_price_non_negative CHECK (list_price >= 0)',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'purchase_order_items' AND constraint_name = 'chk_purchase_order_items_price_reason');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE purchase_order_items ADD CONSTRAINT chk_purchase_order_items_price_reason CHECK ((purchase_price = list_price AND price_reason IS NULL) OR (purchase_price <> list_price AND price_reason IS NOT NULL AND CHAR_LENGTH(TRIM(price_reason)) >= 3))',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

-- sales_order_items ---------------------------------------------------------------------------------------------------
SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'list_price');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE sales_order_items ADD COLUMN list_price DECIMAL(14,2) NULL AFTER selling_price',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

UPDATE sales_order_items SET list_price = selling_price, updated_at = updated_at WHERE list_price IS NULL;

SET @is_nullable := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'list_price' AND is_nullable = 'YES');
SET @ddl := IF(@is_nullable = 1,
    'ALTER TABLE sales_order_items MODIFY COLUMN list_price DECIMAL(14,2) NOT NULL',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'price_reason');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE sales_order_items ADD COLUMN price_reason VARCHAR(255) NULL DEFAULT NULL AFTER list_price',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'sales_order_items' AND constraint_name = 'chk_sales_order_items_list_price_non_negative');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_list_price_non_negative CHECK (list_price >= 0)',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'sales_order_items' AND constraint_name = 'chk_sales_order_items_price_reason');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_price_reason CHECK ((selling_price = list_price AND price_reason IS NULL) OR (selling_price <> list_price AND price_reason IS NOT NULL AND CHAR_LENGTH(TRIM(price_reason)) >= 3))',
    'DO 0');
PREPARE negotiated_price_step FROM @ddl;
EXECUTE negotiated_price_step;
DEALLOCATE PREPARE negotiated_price_step;

INSERT INTO schema_versions (version, description)
VALUES ('negotiated-price-1', 'Negotiated PO/SO line prices with mandatory reason (D-13, ADR-019)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
