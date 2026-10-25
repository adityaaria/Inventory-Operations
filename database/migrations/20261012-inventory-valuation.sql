-- D-20 / ADR-026: inventory valuation FIFO per batch. Phase A: additive and idempotent DDL only. The runner
-- scripts/migrate-inventory-valuation.php then backfills the cost of every ledger row that has a lot (all rows after the
-- ADR-023 watermark) from its lot's unit cost in ONE transaction (App\Repository\MySql\InventoryValuationMigration),
-- verifies every invariant, and runs phase B (20261012-inventory-valuation-finalize.sql: the CHECK) before recording
-- schema version inventory-valuation-1. Rows before the watermark (lot_id NULL) never get a cost.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_ledger' AND column_name = 'unit_cost');
SET @ddl := IF(@has = 0, 'ALTER TABLE stock_ledger ADD COLUMN unit_cost DECIMAL(14,2) NULL DEFAULT NULL AFTER created_at', 'DO 0');
PREPARE valuation_step FROM @ddl; EXECUTE valuation_step; DEALLOCATE PREPARE valuation_step;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_ledger' AND column_name = 'cost_amount');
SET @ddl := IF(@has = 0, 'ALTER TABLE stock_ledger ADD COLUMN cost_amount DECIMAL(24,2) NULL DEFAULT NULL AFTER unit_cost', 'DO 0');
PREPARE valuation_step FROM @ddl; EXECUTE valuation_step; DEALLOCATE PREPARE valuation_step;

SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'stock_ledger' AND index_name = 'idx_stock_ledger_created');
SET @ddl := IF(@has = 0, 'ALTER TABLE stock_ledger ADD KEY idx_stock_ledger_created (created_at)', 'DO 0');
PREPARE valuation_step FROM @ddl; EXECUTE valuation_step; DEALLOCATE PREPARE valuation_step;
