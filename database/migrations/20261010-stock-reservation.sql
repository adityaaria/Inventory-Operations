-- D-12 / ADR-018: stock reservation at sales order approval. Additive and idempotent DDL only.
-- Adds product_stocks.reserved_quantity (default 0) and CHECK (reserved_quantity <= quantity). With every value at 0 the
-- CHECK always holds, so adding it never fails. Setting the reservations of currently Approved sales orders is done by
-- scripts/migrate-stock-reservation.php (StockReservationReconciler): it refuses and reports conflicting orders instead
-- of clamping, and records schema version 'stock-reservation-1' only after a successful reconciliation.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.
SET @has_reserved := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'product_stocks' AND column_name = 'reserved_quantity');
SET @ddl := IF(@has_reserved = 0,
    'ALTER TABLE product_stocks ADD COLUMN reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity',
    'DO 0');
PREPARE stock_reservation_step FROM @ddl;
EXECUTE stock_reservation_step;
DEALLOCATE PREPARE stock_reservation_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'product_stocks' AND constraint_name = 'chk_product_stocks_reserved_lte_quantity');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE product_stocks ADD CONSTRAINT chk_product_stocks_reserved_lte_quantity CHECK (reserved_quantity <= quantity)',
    'DO 0');
PREPARE stock_reservation_step FROM @ddl;
EXECUTE stock_reservation_step;
DEALLOCATE PREPARE stock_reservation_step;
