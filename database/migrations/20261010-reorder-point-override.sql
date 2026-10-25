-- D-10 / ADR-016: per-warehouse reorder point override. Additive and idempotent; never touches quantities or ledger.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.
SET @has_override := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'product_stocks' AND column_name = 'reorder_point_override');
SET @ddl := IF(@has_override = 0,
    'ALTER TABLE product_stocks ADD COLUMN reorder_point_override INT UNSIGNED NULL DEFAULT NULL AFTER quantity',
    'DO 0');
PREPARE reorder_override_step FROM @ddl;
EXECUTE reorder_override_step;
DEALLOCATE PREPARE reorder_override_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'product_stocks' AND constraint_name = 'chk_product_stocks_reorder_override_non_negative');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE product_stocks ADD CONSTRAINT chk_product_stocks_reorder_override_non_negative CHECK (reorder_point_override IS NULL OR reorder_point_override >= 0)',
    'DO 0');
PREPARE reorder_override_step FROM @ddl;
EXECUTE reorder_override_step;
DEALLOCATE PREPARE reorder_override_step;

INSERT INTO schema_versions (version, description)
VALUES ('reorder-override-1', 'Per-warehouse reorder point override (D-10, ADR-016)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
