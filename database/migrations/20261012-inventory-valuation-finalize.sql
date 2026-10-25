-- D-20 / ADR-026: phase B (idempotent), run only after the backfill: a ledger row has a cost exactly when it has a lot,
-- and cost_amount = signed quantity x unit_cost (whole cents). A row left without a cost makes the ALTER fail, so a
-- half-finished backfill can never be finalized. Application code writes the cost with every new row.

SET @has := (SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'chk_stock_ledger_cost');
SET @ddl := IF(@has = 0, 'ALTER TABLE stock_ledger ADD CONSTRAINT chk_stock_ledger_cost CHECK ((lot_id IS NULL AND unit_cost IS NULL AND cost_amount IS NULL)
    OR (lot_id IS NOT NULL AND unit_cost IS NOT NULL AND unit_cost >= 0 AND cost_amount IS NOT NULL
        AND cost_amount = (CASE movement_type WHEN ''Receipt'' THEN CAST(quantity AS SIGNED) WHEN ''Issue'' THEN -CAST(quantity AS SIGNED) ELSE quantity_delta END) * unit_cost))', 'DO 0');
PREPARE valuation_step FROM @ddl; EXECUTE valuation_step; DEALLOCATE PREPARE valuation_step;
