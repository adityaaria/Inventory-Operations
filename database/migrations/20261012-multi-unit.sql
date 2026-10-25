-- D-16 / ADR-022: multiple units of measure with conversion. Phase A (additive and idempotent, no data changes):
--   product_units                         new table (one base unit per product, optional larger units, defaults, barcode,
--                                         per-unit master prices);
--   purchase_order_items / sales_order_items / inventory_operation_items
--                                         unit_code, unit_factor, unit_quantity (nullable here; the data step fills them
--                                         and 20261012-multi-unit-finalize.sql makes them NOT NULL with CHECKs).
-- The data conversion (base = smallest unit, quantities x factor, unit snapshots, per-unit prices) runs in ONE
-- transaction in App\Repository\MySql\MultiUnitMigration, driven by scripts/migrate-multi-unit.php (dry run first).
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

CREATE TABLE IF NOT EXISTS product_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    unit_code VARCHAR(30) NOT NULL,
    factor_to_base INT UNSIGNED NOT NULL,
    barcode VARCHAR(64) NULL DEFAULT NULL,
    purchase_price DECIMAL(14,2) NULL DEFAULT NULL,
    selling_price DECIMAL(14,2) NULL DEFAULT NULL,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    is_purchase_default TINYINT(1) NOT NULL DEFAULT 0,
    is_sales_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    base_key TINYINT GENERATED ALWAYS AS (IF(is_base = 1, 1, NULL)) STORED,
    purchase_default_key TINYINT GENERATED ALWAYS AS (IF(is_purchase_default = 1, 1, NULL)) STORED,
    sales_default_key TINYINT GENERATED ALWAYS AS (IF(is_sales_default = 1, 1, NULL)) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_units_code (product_id, unit_code),
    UNIQUE KEY uq_product_units_barcode (barcode),
    UNIQUE KEY uq_product_units_base (product_id, base_key),
    UNIQUE KEY uq_product_units_purchase_default (product_id, purchase_default_key),
    UNIQUE KEY uq_product_units_sales_default (product_id, sales_default_key),
    CONSTRAINT fk_product_units_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_product_units_factor CHECK ((is_base = 1 AND factor_to_base = 1) OR (is_base = 0 AND factor_to_base >= 2)),
    CONSTRAINT chk_product_units_base_plain CHECK (is_base = 0 OR (is_active = 1 AND purchase_price IS NULL AND selling_price IS NULL)),
    CONSTRAINT chk_product_units_default_active CHECK (is_active = 1 OR (is_purchase_default = 0 AND is_sales_default = 0)),
    CONSTRAINT chk_product_units_prices CHECK ((purchase_price IS NULL OR purchase_price >= 0) AND (selling_price IS NULL OR selling_price >= 0))
) ENGINE=InnoDB;

SET @has_po := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_items' AND column_name = 'unit_code');
SET @ddl := IF(@has_po = 0,
    'ALTER TABLE purchase_order_items ADD COLUMN unit_code VARCHAR(30) NULL AFTER price_reason, ADD COLUMN unit_factor INT UNSIGNED NULL AFTER unit_code, ADD COLUMN unit_quantity INT UNSIGNED NULL AFTER unit_factor',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @has_so := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'unit_code');
SET @ddl := IF(@has_so = 0,
    'ALTER TABLE sales_order_items ADD COLUMN unit_code VARCHAR(30) NULL AFTER issued_quantity, ADD COLUMN unit_factor INT UNSIGNED NULL AFTER unit_code, ADD COLUMN unit_quantity INT UNSIGNED NULL AFTER unit_factor',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;

SET @has_op := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_operation_items' AND column_name = 'unit_code');
SET @ddl := IF(@has_op = 0,
    'ALTER TABLE inventory_operation_items ADD COLUMN unit_code VARCHAR(30) NULL AFTER short_quantity, ADD COLUMN unit_factor INT UNSIGNED NULL AFTER unit_code, ADD COLUMN unit_quantity INT UNSIGNED NULL AFTER unit_factor',
    'DO 0');
PREPARE unit_step FROM @ddl;
EXECUTE unit_step;
DEALLOCATE PREPARE unit_step;
