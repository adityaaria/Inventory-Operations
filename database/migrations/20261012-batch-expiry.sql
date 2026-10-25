-- D-17 / ADR-023: batch/lot tracking, expiry and FEFO. Additive and idempotent DDL only; the data step (LEGACY lots,
-- watermark, tracks_expiry for food and beverage categories, invariant checks, schema version) runs in one transaction in
-- App\Repository\MySql\BatchExpiryMigration, started by scripts/migrate-batch-expiry.php.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'tracks_expiry');
SET @ddl := IF(@has = 0, 'ALTER TABLE products ADD COLUMN tracks_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER reorder_point', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_stocks' AND column_name = 'lot_count');
SET @ddl := IF(@has = 0, 'ALTER TABLE product_stocks ADD COLUMN lot_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER reorder_point_override', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;

CREATE TABLE IF NOT EXISTS stock_lots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    lot_seq INT UNSIGNED NOT NULL,
    lot_code VARCHAR(60) NOT NULL,
    expiry_date DATE NULL DEFAULT NULL,
    expiry_key DATE GENERATED ALWAYS AS (COALESCE(expiry_date, '9999-12-31')) STORED,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unit_cost DECIMAL(14,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    opening_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    source_type VARCHAR(20) NOT NULL,
    source_id INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_lots_seq (product_id, warehouse_id, lot_seq),
    UNIQUE KEY uq_stock_lots_identity (product_id, warehouse_id, lot_code, expiry_key, unit_cost),
    KEY idx_stock_lots_code (lot_code),
    KEY idx_stock_lots_expiry (expiry_date),
    CONSTRAINT fk_stock_lots_pair FOREIGN KEY (product_id, warehouse_id) REFERENCES product_stocks (product_id, warehouse_id),
    CONSTRAINT chk_stock_lots_seq CHECK (lot_seq >= 1),
    CONSTRAINT chk_stock_lots_code CHECK (CHAR_LENGTH(TRIM(lot_code)) >= 1),
    CONSTRAINT chk_stock_lots_cost CHECK (unit_cost >= 0),
    CONSTRAINT chk_stock_lots_quantity CHECK (quantity >= 0 AND opening_quantity >= 0),
    CONSTRAINT chk_stock_lots_opening CHECK (source_type = 'LEGACY' OR opening_quantity = 0),
    CONSTRAINT chk_stock_lots_source CHECK (source_type IN ('PO', 'TRANSFER', 'ADJUSTMENT', 'CUSTOMER_RETURN', 'LEGACY', 'RECEIPT'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_lot_watermark (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    legacy_ledger_max_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_stock_lot_watermark_single CHECK (id = 1)
) ENGINE=InnoDB;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_ledger' AND column_name = 'lot_id');
SET @ddl := IF(@has = 0, 'ALTER TABLE stock_ledger ADD COLUMN lot_id INT UNSIGNED NULL DEFAULT NULL AFTER performed_by, ADD KEY idx_stock_ledger_lot (lot_id, id), ADD CONSTRAINT fk_stock_ledger_lot FOREIGN KEY (lot_id) REFERENCES stock_lots (id)', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_operations' AND column_name = 'purpose');
SET @ddl := IF(@has = 0, 'ALTER TABLE inventory_operations ADD COLUMN purpose ENUM(''Standard'',''ExpiredWriteOff'') NOT NULL DEFAULT ''Standard'' AFTER receipt_reason, ADD CONSTRAINT chk_operation_purpose CHECK (purpose=''Standard'' OR kind=''Adjustment'')', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_operation_items' AND column_name = 'lot_id');
SET @ddl := IF(@has = 0, 'ALTER TABLE inventory_operation_items ADD COLUMN lot_id INT UNSIGNED NULL, ADD COLUMN lot_code VARCHAR(60) NULL, ADD COLUMN expiry_date DATE NULL, ADD COLUMN lot_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(lot_id, 0)) STORED, ADD CONSTRAINT fk_operation_items_lot FOREIGN KEY (lot_id) REFERENCES stock_lots(id), ADD CONSTRAINT chk_operation_items_lot CHECK (lot_id IS NULL OR lot_code IS NULL), ADD CONSTRAINT chk_operation_items_lot_expiry CHECK (expiry_date IS NULL OR lot_code IS NOT NULL)', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;

-- One line per product and lot replaces one line per product (adjustments count lots); the new key is added first so
-- the operation_id foreign key always has an index.
SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'inventory_operation_items' AND index_name = 'uq_operation_product_lot');
SET @ddl := IF(@has = 0, 'ALTER TABLE inventory_operation_items ADD UNIQUE KEY uq_operation_product_lot (operation_id, product_id, lot_key)', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;
SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'inventory_operation_items' AND index_name = 'uq_operation_product');
SET @ddl := IF(@has > 0, 'ALTER TABLE inventory_operation_items DROP INDEX uq_operation_product', 'DO 0');
PREPARE lot_step FROM @ddl; EXECUTE lot_step; DEALLOCATE PREPARE lot_step;

CREATE TABLE IF NOT EXISTS inventory_operation_lots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 operation_id INT UNSIGNED NOT NULL, operation_item_id BIGINT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL,
 source_lot_id INT UNSIGNED NOT NULL, lot_code VARCHAR(60) NOT NULL, expiry_date DATE NULL, unit_cost DECIMAL(14,2) NOT NULL,
 dispatched_quantity INT UNSIGNED NOT NULL,
 received_quantity INT UNSIGNED NULL, damaged_quantity INT UNSIGNED NULL, short_quantity INT UNSIGNED NULL,
 destination_lot_id INT UNSIGNED NULL,
 UNIQUE KEY uq_operation_lots_item_lot (operation_item_id, source_lot_id),
 KEY idx_operation_lots_operation (operation_id),
 KEY idx_operation_lots_destination (destination_lot_id),
 CONSTRAINT fk_operation_lots_operation FOREIGN KEY (operation_id) REFERENCES inventory_operations(id),
 CONSTRAINT fk_operation_lots_item FOREIGN KEY (operation_item_id) REFERENCES inventory_operation_items(id),
 CONSTRAINT fk_operation_lots_source FOREIGN KEY (source_lot_id) REFERENCES stock_lots(id),
 CONSTRAINT fk_operation_lots_destination FOREIGN KEY (destination_lot_id) REFERENCES stock_lots(id),
 CONSTRAINT chk_operation_lots_dispatched CHECK (dispatched_quantity > 0),
 CONSTRAINT chk_operation_lots_receipt CHECK ((received_quantity IS NULL AND damaged_quantity IS NULL AND short_quantity IS NULL)
   OR (received_quantity IS NOT NULL AND damaged_quantity IS NOT NULL AND short_quantity IS NOT NULL
       AND received_quantity + damaged_quantity + short_quantity = dispatched_quantity)),
 CONSTRAINT chk_operation_lots_destination CHECK (destination_lot_id IS NULL OR received_quantity > 0)
) ENGINE=InnoDB;
